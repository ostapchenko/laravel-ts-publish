<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;

/**
 * A call to a known PHP built-in function (`count(...)`, `strtoupper(...)`, etc.), typed from its
 * reflected return type. Declines a userland function or one reflection can't map to a TS scalar.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class KnownFunctionCallHandler implements ExpressionHandler
{
    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [FuncCall::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            $tsType = $this->resolveKnownFunctionCallType($expr->name->getLast());

            if ($tsType !== null) {
                return ['type' => $tsType, 'optional' => false];
            }
        }

        return null;
    }

    /**
     * Resolve a PHP built-in function name to its TypeScript return type, or null when unresolvable.
     */
    private function resolveKnownFunctionCallType(string $name): ?string
    {
        $tsInfo = LaravelTsPublish::nativePhpFunctionReturnedTypes($name);

        return ! str_contains($tsInfo['type'], 'unknown') ? $tsInfo['type'] : null;
    }
}
