<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast;

use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;

/**
 * Shared building blocks for ExpressionHandler results.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class ValueResult
{
    /**
     * The fallback result for an expression that resolves to no useful type.
     *
     * @return ValueExpressionResult
     */
    public static function unknown(): array
    {
        return ['type' => 'unknown', 'optional' => false];
    }
}
