<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast;

use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

/**
 * Reads a method whose whole body is one string-literal return, e.g. `broadcastAs()`.
 *
 * Whole literal or nothing: a concatenation folded to its literal prefix ships a wrong Echo key.
 */
final class ReturnLiteralReader
{
    /**
     * The single string literal a method returns, or null for anything else.
     *
     * @param  class-string  $class
     */
    public function stringLiteral(string $class, string $method): ?string
    {
        $context = resolve(MethodLocator::class)->locate($class, $method);

        if ($context === null) {
            return null;
        }

        /** @var list<Return_> $returns */
        $returns = new NodeFinder()->findInstanceOf($context->method->stmts ?? [], Return_::class);

        if (count($returns) !== 1) {
            return null;
        }

        $expr = $returns[0]->expr;

        return $expr instanceof String_ ? $expr->value : null;
    }
}
