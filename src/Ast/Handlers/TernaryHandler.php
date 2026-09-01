<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Ternary;

/**
 * Ternary and Elvis expressions — both arms resolved through the engine and unioned.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class TernaryHandler implements ExpressionHandler
{
    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [Ternary::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        if ($expr instanceof Ternary) {
            return $this->analyzeTernary($expr, $engine);
        }

        return null;
    }

    /**
     * Analyze a ternary or Elvis expression, unioning both branches.
     *
     * In Elvis (`$cond ?: $else`) the parser leaves `if` null, so the truthy value is `$cond` itself.
     *
     * @return ValueExpressionResult
     */
    private function analyzeTernary(Ternary $expr, ExpressionEngine $engine): array
    {
        if ($expr->if === null) {
            return ValueResult::analyzeClosureUnion([$expr->cond, $expr->else], $engine);
        }

        return ValueResult::analyzeClosureUnion([$expr->if, $expr->else], $engine);
    }
}
