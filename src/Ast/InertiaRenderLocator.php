<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Locates a method's Inertia render calls and reads their component names and props arguments.
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
     * Every Inertia render call in a method — `Inertia::render()`, `inertia()`, and
     * `inertia()->render()` — normalized into name/props argument pairs, in source order.
     *
     * @return list<RenderCall>
     */
    public function findRenderCalls(ClassMethod $method): array
    {
        if ($method->stmts === null) {
            return [];
        }

        $nodes = (new NodeFinder)->find(
            $method->stmts,
            fn (Node $node): bool => $this->matcher->isStaticCallTo($node, 'Inertia', 'render')
                || $this->isInertiaHelperCall($node)
                || $this->isInertiaHelperRenderCall($node),
        );

        $calls = [];

        foreach ($nodes as $node) {
            if (! $node instanceof StaticCall && ! $node instanceof FuncCall && ! $node instanceof MethodCall) {
                continue; // @codeCoverageIgnore
            }

            $args = $node->isFirstClassCallable() ? [] : $node->getArgs();

            $calls[] = new RenderCall($args[0]->value ?? null, $args[1]->value ?? null);
        }

        return $calls;
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

    /**
     * Whether the node is an `inertia('Component', [...])` helper call carrying arguments.
     */
    protected function isInertiaHelperCall(Node $node): bool
    {
        return $node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'inertia'
            && ! $node->isFirstClassCallable()
            && $node->getArgs() !== [];
    }

    /**
     * Whether the node is an `inertia()->render('Component', [...])` call.
     */
    protected function isInertiaHelperRenderCall(Node $node): bool
    {
        return $node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'render'
            && $node->var instanceof FuncCall
            && $node->var->name instanceof Name
            && $node->var->name->toString() === 'inertia';
    }
}
