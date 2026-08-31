<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Contracts;

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
}
