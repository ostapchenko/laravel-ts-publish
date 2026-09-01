<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * Inertia's prop wrappers — `Inertia::defer()`, `optional()`, `lazy()`, `always()`, `merge()`,
 * `deepMerge()` — resolve to the type of the value they wrap. The three lazy wrappers are absent
 * from a partial reload's payload, so their result is optional.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class InertiaWrapperHandler implements ExpressionHandler
{
    /** Wrapper names whose prop is omitted unless explicitly requested. */
    private const OPTIONAL_WRAPPERS = ['defer', 'optional', 'lazy'];

    private const WRAPPERS = ['defer', 'optional', 'lazy', 'always', 'merge', 'deepMerge'];

    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [StaticCall::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        if (! $expr instanceof StaticCall
            || ! $expr->class instanceof Name
            || $expr->class->getLast() !== 'Inertia'
            || ! $expr->name instanceof Identifier
            || ! in_array($expr->name->toString(), self::WRAPPERS, true)
            || $expr->isFirstClassCallable()) {
            return null;
        }

        $args = $expr->getArgs();

        if ($args === []) {
            return null;
        }

        $result = $engine->resolve($args[0]->value);

        return [
            ...$result,
            'optional' => $result['optional'] || in_array($expr->name->toString(), self::OPTIONAL_WRAPPERS, true),
        ];
    }
}
