<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;

/**
 * `array_merge([...], parent::share($request))` in value position. Its arguments are re-expressed as
 * one array literal — later keys win, exactly as at runtime — and resolved through the inline-array
 * path, so spreads, enum wrapping and import channels all behave identically. Declines when any
 * argument is neither an array literal nor a `parent::` call, since those keys cannot be read.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class ArrayMergeHandler implements ExpressionHandler
{
    use InspectsAstNodes;

    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [FuncCall::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        if (! $expr instanceof FuncCall
            || ! $expr->name instanceof Name
            || $expr->name->getLast() !== 'array_merge'
            || $expr->isFirstClassCallable()) {
            return null;
        }

        $items = [];

        foreach ($expr->getArgs() as $arg) {
            if ($arg->value instanceof Array_) {
                $items = [...$items, ...$arg->value->items];

                continue;
            }

            if (! $this->isParentCallTo($arg->value)) {
                return null;
            }

            $items[] = new ArrayItem($arg->value, byRef: false, unpack: true);
        }

        return $engine->resolve(new Array_($items));
    }
}
