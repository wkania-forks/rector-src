<?php

declare(strict_types=1);

namespace Rector\TypeDeclaration\NodeAnalyzer;

use PhpParser\Node\Arg;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use Rector\Core\PhpParser\AstResolver;
use Rector\NodeNameResolver\NodeNameResolver;

final class CallerParamMatcher
{
    public function __construct(
        private NodeNameResolver $nodeNameResolver,
        private AstResolver $astResolver
    ) {
    }

    public function matchCallParamType(
        StaticCall | MethodCall | FuncCall $call,
        Param $param,
        Scope $scope
    ): null | Identifier | Name | NullableType | UnionType | ComplexType {
        $callParam = $this->matchCallParam($call, $param, $scope);
        if (! $callParam instanceof Param) {
            return null;
        }

        return $callParam->type;
    }

    public function matchCallParam(StaticCall | MethodCall | FuncCall $call, Param $param, Scope $scope): ?Param
    {
        $callArgPosition = $this->matchCallArgPosition($call, $param);
        if ($callArgPosition === null) {
            return null;
        }

        $classMethodOrFunction = $this->astResolver->resolveClassMethodOrFunctionFromCall($call, $scope);
        if ($classMethodOrFunction === null) {
            return null;
        }

        return $classMethodOrFunction->params[$callArgPosition] ?? null;
    }

    public function matchParentParam(StaticCall $parentStaticCall, Param $param, Scope $scope): ?Param
    {
        $methodName = $this->nodeNameResolver->getName($parentStaticCall->name);
        if ($methodName === null) {
            return null;
        }

        // match current param to parent call position
        $parentStaticCallArgPosition = $this->matchCallArgPosition($parentStaticCall, $param);
        if ($parentStaticCallArgPosition === null) {
            return null;
        }

        return $this->resolveParentMethodParam($scope, $methodName, $parentStaticCallArgPosition);
    }

    private function matchCallArgPosition(StaticCall | MethodCall | FuncCall $call, Param $param): int | null
    {
        $paramName = $this->nodeNameResolver->getName($param);

        foreach ($call->args as $argPosition => $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }

            if (! $arg->value instanceof Variable) {
                continue;
            }

            if (! $this->nodeNameResolver->isName($arg->value, $paramName)) {
                continue;
            }

            return $argPosition;
        }

        return null;
    }

    private function resolveParentMethodParam(Scope $scope, string $methodName, int $paramPosition): ?Param
    {
        $classReflection = $scope->getClassReflection();
        if (! $classReflection instanceof ClassReflection) {
            return null;
        }

        foreach ($classReflection->getParents() as $parentClassReflection) {
            if (! $parentClassReflection->hasMethod($methodName)) {
                continue;
            }

            $parentClassMethod = $this->astResolver->resolveClassMethod($parentClassReflection->getName(), $methodName);
            if (! $parentClassMethod instanceof ClassMethod) {
                continue;
            }

            return $parentClassMethod->params[$paramPosition] ?? null;
        }

        return null;
    }
}
