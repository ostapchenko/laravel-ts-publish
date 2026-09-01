<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast;

use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\BinaryOpHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\CastHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ClassConstantHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ClosureHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\CoalesceHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ConditionalMethodHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ConstFetchHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\FirstClassCallableHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\InlineArrayHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\KnownFunctionCallHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\KnownMethodRuleHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\MethodChainHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\NewResourceHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\PropertyChainHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\RelationCollectionChainHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\RelationFilterHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ScalarHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\StaticCallHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\TernaryHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ThisPropertyHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ToResourceHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\VariableHandler;

/**
 * Builds the ordered ExpressionHandler lists ExpressionDispatcher runs. Registration order is
 * dispatch precedence for any node class more than one handler claims — see
 * docs/components/ast-engine.md's "Handler ordering" section for the contract this pins.
 */
final class ResourceExpressionHandlers
{
    /**
     * The full resource profile — all 22 handlers extracted in Tasks 14-22, in their chain-derived
     * dispatch order. $engine is accepted, not consumed: it mirrors the call site
     * (`ResourceExpressionHandlers::make($this)`) so a future handler needing the engine at
     * construction can be added without changing every caller.
     *
     * @return list<ExpressionHandler>
     */
    public static function make(ExpressionEngine $engine): array
    {
        return self::handlers();
    }

    /**
     * The class-agnostic profile: make() minus the three resource-only handlers
     * (ConditionalMethodHandler, ToResourceHandler, RelationFilterHandler), same relative order.
     *
     * @return list<ExpressionHandler>
     */
    public static function generic(): array
    {
        return array_values(array_filter(
            self::handlers(),
            static fn (ExpressionHandler $handler): bool => ! $handler instanceof ConditionalMethodHandler
                && ! $handler instanceof ToResourceHandler
                && ! $handler instanceof RelationFilterHandler,
        ));
    }

    /**
     * Construct all 22 handlers in registration order — the single source both profiles above filter.
     *
     * @return list<ExpressionHandler>
     */
    private static function handlers(): array
    {
        return [
            new FirstClassCallableHandler,
            new CastHandler,
            new ScalarHandler,
            new ConstFetchHandler,
            new ClassConstantHandler,
            new BinaryOpHandler,
            new CoalesceHandler,
            new KnownFunctionCallHandler,
            new ClosureHandler,
            new ConditionalMethodHandler,
            new ToResourceHandler,
            new StaticCallHandler,
            new NewResourceHandler,
            new ThisPropertyHandler,
            new RelationFilterHandler,
            new InlineArrayHandler,
            new MethodChainHandler,
            new PropertyChainHandler,
            new RelationCollectionChainHandler,
            new VariableHandler,
            new TernaryHandler,
            new KnownMethodRuleHandler,
        ];
    }
}
