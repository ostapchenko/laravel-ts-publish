<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Contracts;

use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;
use PhpParser\Node\Expr;

/**
 * Full expression resolution, for handlers to call back into on sub-expressions they don't own.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
interface ExpressionEngine
{
    /**
     * Resolve an expression to its TypeScript type: extracted handlers first, the legacy chain after.
     *
     * @return ValueExpressionResult
     */
    public function resolve(Expr $expr): array;

    /**
     * Spread-analyze a named method on the subject under analysis, for a handler that resolves a
     * self-returning chain onto a non-preserving method body instead of degrading to `unknown`.
     */
    public function spreadAnalysis(string $methodName): ?MethodAnalysis;
}
