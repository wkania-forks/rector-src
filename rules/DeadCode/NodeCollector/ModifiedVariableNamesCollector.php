<?php

declare(strict_types=1);

namespace Rector\DeadCode\NodeCollector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Symplify\Astral\NodeTraverser\SimpleCallableNodeTraverser;

final class ModifiedVariableNamesCollector
{
    public function __construct(
        private SimpleCallableNodeTraverser $simpleCallableNodeTraverser,
        private NodeNameResolver $nodeNameResolver
    ) {
    }

    /**
     * @return string[]
     */
    public function collectModifiedVariableNames(Stmt $stmt): array
    {
        $argNames = $this->collectFromArgs($stmt);
        $assignNames = $this->collectFromAssigns($stmt);

        return array_merge($argNames, $assignNames);
    }

    /**
     * @return string[]
     */
    private function collectFromArgs(Stmt $stmt): array
    {
        $variableNames = [];

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($stmt, function (Node $node) use (
            &$variableNames
        ) {
            if (! $node instanceof Arg) {
                return null;
            }

            if (! $this->isVariableChangedInReference($node)) {
                return null;
            }

            $variableName = $this->nodeNameResolver->getName($node->value);
            if ($variableName === null) {
                return null;
            }

            $variableNames[] = $variableName;
        });

        return $variableNames;
    }

    /**
     * @return string[]
     */
    private function collectFromAssigns(Stmt $stmt): array
    {
        $modifiedVariableNames = [];

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($stmt, function (Node $node) use (
            &$modifiedVariableNames
        ) {
            if (! $node instanceof Assign) {
                return null;
            }

            if (! $node->var instanceof Variable) {
                return null;
            }

            $variableName = $this->nodeNameResolver->getName($node->var);
            if ($variableName === null) {
                return null;
            }

            $modifiedVariableNames[] = $variableName;
        });

        return $modifiedVariableNames;
    }

    private function isVariableChangedInReference(Arg $arg): bool
    {
        $parentNode = $arg->getAttribute(AttributeKey::PARENT_NODE);
        if (! $parentNode instanceof FuncCall) {
            return false;
        }

        return $this->nodeNameResolver->isNames($parentNode, ['array_shift', 'array_pop']);
    }
}
