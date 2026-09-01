<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;

/**
 * Analyze a null-coalescing expression (`$left ?? $right`).
 *
 * Doesn't delegate to ClosureHandler::analyzeClosureUnion(): that would leave `null` in twice
 * (`Order | null | Order`). Only operands contributing a result member get their channels merged.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class CoalesceHandler implements ExpressionHandler
{
    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [BinaryOp\Coalesce::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        if ($expr instanceof BinaryOp\Coalesce) {
            $leftResult = $engine->resolve($expr->left);
            $rightResult = $engine->resolve($expr->right);

            $leftType = $leftResult['type'];
            $rightType = $rightResult['type'];

            // Strip `| null` from the left: with a non-null fallback, null is never the final result.
            $leftType = $this->stripNullArm($leftType);

            if ($leftType === 'unknown' || $leftType === '') {
                return ValueResult::mergeUnion([$rightType], [$rightResult]);
            }

            if ($rightType === 'unknown') {
                return ValueResult::mergeUnion([$leftType], [$leftResult]);
            }

            if ($leftType === $rightType) {
                return ValueResult::mergeUnion([$leftType], [$leftResult, $rightResult]);
            }

            return ValueResult::mergeUnion([$leftType, $rightType], [$leftResult, $rightResult]);
        }

        return null;
    }

    /**
     * Drop a top-level `| null` arm from a type string — a guarded success path proves it unreachable.
     * Nested null members (inside object shapes, generics, or array element types) are kept.
     *
     * Duplicated here — a standalone handler can't call the analyzer's `protected` helpers. Task 20
     * (Slice S7) moves stripNullArm() to its S7 home and repoints this handler there.
     */
    private function stripNullArm(string $type): string
    {
        $members = array_values(array_filter(
            LaravelTsPublish::splitTopLevelUnion($type),
            fn (string $member): bool => $member !== 'null',
        ));

        return $members === [] ? 'unknown' : implode(' | ', $members);
    }
}
