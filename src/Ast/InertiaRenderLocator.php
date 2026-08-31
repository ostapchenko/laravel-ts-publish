<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Locates a method's Inertia::render() call and reads its component name and props argument.
 */
class InertiaRenderLocator
{
    public function __construct(protected CallMatcher $matcher) {}

    /**
     * Find the first Inertia::render(...) call in a method.
     */
    public function findRenderCall(ClassMethod $method): ?StaticCall
    {
        if ($method->stmts === null) {
            return null;
        }

        /** @var StaticCall|null $call */
        $call = (new NodeFinder)->findFirst(
            $method->stmts,
            fn (Node $node): bool => $this->matcher->isStaticCallTo($node, 'Inertia', 'render'),
        );

        return $call;
    }

    /**
     * Resolve the component string from Inertia::render('Component', ...).
     */
    public function componentName(StaticCall $render): ?string
    {
        $firstArg = $render->args[0] ?? null;

        if (! $firstArg instanceof Node\Arg || ! $firstArg->value instanceof String_) {
            return null;
        }

        return $firstArg->value->value;
    }

    /**
     * The raw second-argument expression of Inertia::render(...), whatever its shape.
     */
    public function propsArg(StaticCall $render): ?Expr
    {
        $secondArg = $render->args[1] ?? null;

        return $secondArg instanceof Node\Arg ? $secondArg->value : null;
    }

    /**
     * propsArg() narrowed to an inline array literal.
     */
    public function propsArray(StaticCall $render): ?Array_
    {
        $expr = $this->propsArg($render);

        return $expr instanceof Array_ ? $expr : null;
    }
}
