<?php

declare(strict_types=1);

namespace Rector\NodeTypeResolver;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeWithClassName;
use PHPStan\Type\UnionType;
use Rector\Core\Configuration\RenamedClassesDataCollector;
use Rector\Core\NodeAnalyzer\ClassAnalyzer;
use Rector\NodeTypeResolver\Contract\NodeTypeResolverInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeCorrector\AccessoryNonEmptyStringTypeCorrector;
use Rector\NodeTypeResolver\NodeTypeCorrector\GenericClassStringTypeCorrector;
use Rector\NodeTypeResolver\NodeTypeCorrector\HasOffsetTypeCorrector;
use Rector\NodeTypeResolver\NodeTypeResolver\IdentifierTypeResolver;
use Rector\NodeTypeResolver\TypeAnalyzer\ArrayTypeAnalyzer;
use Rector\StaticTypeMapper\ValueObject\Type\ShortenedObjectType;
use Rector\TypeDeclaration\PHPStan\Type\ObjectTypeSpecifier;
use Symfony\Contracts\Service\Attribute\Required;

final class NodeTypeResolver
{
    /**
     * @var array<class-string<Node>, NodeTypeResolverInterface>
     */
    private array $nodeTypeResolvers = [];

    private ArrayTypeAnalyzer $arrayTypeAnalyzer;

    /**
     * @param NodeTypeResolverInterface[] $nodeTypeResolvers
     */
    public function __construct(
        private ObjectTypeSpecifier $objectTypeSpecifier,
        private ClassAnalyzer $classAnalyzer,
        private GenericClassStringTypeCorrector $genericClassStringTypeCorrector,
        private ReflectionProvider $reflectionProvider,
        private HasOffsetTypeCorrector $hasOffsetTypeCorrector,
        private AccessoryNonEmptyStringTypeCorrector $accessoryNonEmptyStringTypeCorrector,
        private IdentifierTypeResolver $identifierTypeResolver,
        private RenamedClassesDataCollector $renamedClassesDataCollector,
        array $nodeTypeResolvers
    ) {
        foreach ($nodeTypeResolvers as $nodeTypeResolver) {
            $this->addNodeTypeResolver($nodeTypeResolver);
        }
    }

    // Prevents circular dependency

    #[Required]
    public function autowireNodeTypeResolver(ArrayTypeAnalyzer $arrayTypeAnalyzer): void
    {
        $this->arrayTypeAnalyzer = $arrayTypeAnalyzer;
    }

    /**
     * @param ObjectType[] $requiredTypes
     */
    public function isObjectTypes(Node $node, array $requiredTypes): bool
    {
        foreach ($requiredTypes as $requiredType) {
            if ($this->isObjectType($node, $requiredType)) {
                return true;
            }
        }

        return false;
    }

    public function isObjectType(Node $node, ObjectType $requiredObjectType): bool
    {
        if ($node instanceof ClassConstFetch) {
            return false;
        }

        $resolvedType = $this->getType($node);
        if ($resolvedType instanceof MixedType) {
            return false;
        }

        if ($resolvedType instanceof ThisType) {
            $resolvedType = $resolvedType->getStaticObjectType();
        }

        if ($resolvedType instanceof ObjectType) {
            return $this->resolveObjectType($resolvedType, $requiredObjectType);
        }

        return $this->isMatchingUnionType($resolvedType, $requiredObjectType);
    }

    /**
     * @deprecated
     * @see use NodeTypeResolver::getType() instead
     */
    public function resolve(Node $node): Type
    {
        $errorMessage = sprintf('Method "%s" is deprecated. Use "getType()" instead', __METHOD__);
        trigger_error($errorMessage, E_USER_WARNING);
        sleep(3);

        return $this->getType($node);
    }

    public function getType(Node $node): Type
    {
        if ($node instanceof Ternary) {
            $ternaryType = $this->resolveTernaryType($node);
            if (! $ternaryType instanceof MixedType) {
                return $ternaryType;
            }
        }

        if ($node instanceof Coalesce) {
            $first = $this->getType($node->left);
            $second = $this->getType($node->right);

            if ($this->isUnionTypeable($first, $second)) {
                return new UnionType([$first, $second]);
            }
        }

        $type = $this->resolveByNodeTypeResolvers($node);
        if ($type !== null) {
            $type = $this->accessoryNonEmptyStringTypeCorrector->correct($type);

            $type = $this->genericClassStringTypeCorrector->correct($type);

            if ($type instanceof ObjectType) {
                // we want to keep aliased object types
                $type = $this->objectTypeSpecifier->narrowToFullyQualifiedOrAliasedObjectType($node, $type);
            }

            return $this->hasOffsetTypeCorrector->correct($type);
        }

        $scope = $node->getAttribute(AttributeKey::SCOPE);

        if (! $scope instanceof Scope) {
            if ($node instanceof ConstFetch && $node->name instanceof Name) {
                $name = (string) $node->name;
                if (strtolower($name) === 'null') {
                    return new NullType();
                }
            }

            return new MixedType();
        }

        if (! $node instanceof Expr) {
            // scalar type, e.g. from param type name
            if ($node instanceof Identifier) {
                return $this->identifierTypeResolver->resolve($node);
            }

            return new MixedType();
        }

        // skip anonymous classes, ref https://github.com/rectorphp/rector/issues/1574
        if ($node instanceof New_ && $this->classAnalyzer->isAnonymousClass($node->class)) {
            return new ObjectWithoutClassType();
        }

        $type = $scope->getType($node);
        $type = $this->accessoryNonEmptyStringTypeCorrector->correct($type);
        $type = $this->genericClassStringTypeCorrector->correct($type);

        // hot fix for phpstan not resolving chain method calls
        if (! $node instanceof MethodCall) {
            return $type;
        }

        if (! $type instanceof MixedType) {
            return $type;
        }

        return $this->getType($node->var);
    }

    /**
     * e.g. string|null, ObjectNull|null
     */
    public function isNullableType(Node $node): bool
    {
        $nodeType = $this->getType($node);
        return TypeCombinator::containsNull($nodeType);
    }

    public function getNativeType(Expr $expr): Type
    {
        $scope = $expr->getAttribute(AttributeKey::SCOPE);
        if (! $scope instanceof Scope) {
            return new MixedType();
        }

        return $scope->getNativeType($expr);
    }

    /**
     * @deprecated
     * @see Use NodeTypeResolver::getType() instead
     */
    public function getStaticType(Node $node): Type
    {
        $errorMessage = sprintf('Method "%s" is deprecated. Use "getType()" instead', __METHOD__);
        trigger_error($errorMessage, E_USER_WARNING);
        sleep(3);

        if ($node instanceof Param || $node instanceof New_ || $node instanceof Return_) {
            return $this->getType($node);
        }

        if (! $node instanceof Expr) {
            return new MixedType();
        }

        if ($this->arrayTypeAnalyzer->isArrayType($node)) {
            return $this->resolveArrayType($node);
        }

        if ($node instanceof Scalar) {
            return $this->getType($node);
        }

        $scope = $node->getAttribute(AttributeKey::SCOPE);
        if (! $scope instanceof Scope) {
            return new MixedType();
        }

        $staticType = $scope->getType($node);
        if ($staticType instanceof GenericObjectType) {
            return $staticType;
        }

        if ($staticType instanceof ObjectType) {
            return $this->objectTypeSpecifier->narrowToFullyQualifiedOrAliasedObjectType($node, $staticType);
        }

        return $this->accessoryNonEmptyStringTypeCorrector->correct($staticType);
    }

    public function isNumberType(Node $node): bool
    {
        $nodeType = $this->getType($node);
        if ($nodeType instanceof IntegerType) {
            return true;
        }

        return $nodeType instanceof FloatType;
    }

    /**
     * @param class-string<Type> $desiredType
     */
    public function isNullableTypeOfSpecificType(Node $node, string $desiredType): bool
    {
        $nodeType = $this->getType($node);
        if (! $nodeType instanceof UnionType) {
            return false;
        }

        if (! TypeCombinator::containsNull($nodeType)) {
            return false;
        }

        $bareType = TypeCombinator::removeNull($nodeType);
        return is_a($bareType, $desiredType, true);
    }

    /**
     * @return class-string
     */
    public function getFullyQualifiedClassName(TypeWithClassName $typeWithClassName): string
    {
        if ($typeWithClassName instanceof ShortenedObjectType) {
            return $typeWithClassName->getFullyQualifiedName();
        }

        return $typeWithClassName->getClassName();
    }

    public function isMethodStaticCallOrClassMethodObjectType(Node $node, ObjectType $objectType): bool
    {
        if ($node instanceof MethodCall) {
            // method call is variable return
            return $this->isObjectType($node->var, $objectType);
        }

        if ($node instanceof StaticCall) {
            return $this->isObjectType($node->class, $objectType);
        }

        $classLike = $node->getAttribute(AttributeKey::CLASS_NODE);
        if (! $classLike instanceof Class_) {
            return false;
        }

        return $this->isObjectType($classLike, $objectType);
    }

    private function isUnionTypeable(Type $first, Type $second): bool
    {
        return ! $first instanceof UnionType && ! $second instanceof UnionType && ! $second instanceof NullType;
    }

    private function addNodeTypeResolver(NodeTypeResolverInterface $nodeTypeResolver): void
    {
        foreach ($nodeTypeResolver->getNodeClasses() as $nodeClass) {
            $this->nodeTypeResolvers[$nodeClass] = $nodeTypeResolver;
        }
    }

    private function isMatchingUnionType(Type $resolvedType, ObjectType $requiredObjectType): bool
    {
        $type = TypeCombinator::removeNull($resolvedType);
        // for falsy nullables
        $type = TypeCombinator::remove($type, new ConstantBooleanType(false));

        if (! $type instanceof ObjectType) {
            return false;
        }

        return $type->isInstanceOf($requiredObjectType->getClassName())
            ->yes();
    }

    private function resolveArrayType(Expr $expr): Type
    {
        /** @var Scope|null $scope */
        $scope = $expr->getAttribute(AttributeKey::SCOPE);

        if ($scope instanceof Scope) {
            $arrayType = $scope->getType($expr);
            $arrayType = $this->genericClassStringTypeCorrector->correct($arrayType);
            return $this->removeNonEmptyArrayFromIntersectionWithArrayType($arrayType);
        }

        return new ArrayType(new MixedType(), new MixedType());
    }

    private function resolveByNodeTypeResolvers(Node $node): ?Type
    {
        foreach ($this->nodeTypeResolvers as $nodeClass => $nodeTypeResolver) {
            if (! is_a($node, $nodeClass, true)) {
                continue;
            }

            return $nodeTypeResolver->resolve($node);
        }

        return null;
    }

    private function removeNonEmptyArrayFromIntersectionWithArrayType(Type $type): Type
    {
        if (! $type instanceof IntersectionType) {
            return $type;
        }

        if (count($type->getTypes()) !== 2) {
            return $type;
        }

        if (! $type->isSubTypeOf(new NonEmptyArrayType())->yes()) {
            return $type;
        }

        $otherType = null;
        foreach ($type->getTypes() as $intersectionedType) {
            if ($intersectionedType instanceof NonEmptyArrayType) {
                continue;
            }

            $otherType = $intersectionedType;
            break;
        }

        if ($otherType === null) {
            return $type;
        }

        return $otherType;
    }

    private function isObjectTypeOfObjectType(ObjectType $resolvedObjectType, ObjectType $requiredObjectType): bool
    {
        if ($resolvedObjectType->isInstanceOf($requiredObjectType->getClassName())->yes()) {
            return true;
        }

        if ($resolvedObjectType->getClassName() === $requiredObjectType->getClassName()) {
            return true;
        }

        if (! $this->reflectionProvider->hasClass($resolvedObjectType->getClassName())) {
            return false;
        }

        $classReflection = $this->reflectionProvider->getClass($resolvedObjectType->getClassName());
        foreach ($classReflection->getAncestors() as $ancestorClassReflection) {
            if ($ancestorClassReflection->hasTraitUse($requiredObjectType->getClassName())) {
                return true;
            }
        }

        return $classReflection->isSubclassOf($requiredObjectType->getClassName());
    }

    private function resolveObjectType(ObjectType $resolvedObjectType, ObjectType $requiredObjectType): bool
    {
        $renamedObjectType = $this->renamedClassesDataCollector->matchClassName($resolvedObjectType);
        if (! $renamedObjectType instanceof ObjectType) {
            return $this->isObjectTypeOfObjectType($resolvedObjectType, $requiredObjectType);
        }

        if (! $this->isObjectTypeOfObjectType($renamedObjectType, $requiredObjectType)) {
            return $this->isObjectTypeOfObjectType($resolvedObjectType, $requiredObjectType);
        }

        return true;
    }

    private function resolveTernaryType(Ternary $ternary): MixedType|UnionType
    {
        if ($ternary->if !== null) {
            $first = $this->getType($ternary->if);
            $second = $this->getType($ternary->else);

            if ($this->isUnionTypeable($first, $second)) {
                return new UnionType([$first, $second]);
            }
        }

        $condType = $this->getType($ternary->cond);
        if ($this->isNullableType($ternary->cond) && $condType instanceof UnionType) {
            $first = $condType->getTypes()[0];
            $second = $this->getType($ternary->else);

            if ($this->isUnionTypeable($first, $second)) {
                return new UnionType([$first, $second]);
            }
        }

        return new MixedType();
    }
}
