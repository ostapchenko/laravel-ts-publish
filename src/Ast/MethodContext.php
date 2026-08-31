<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionClass;

/**
 * A located class method: its reflection, its ClassMethod AST node, and the parsed file it lives in.
 */
final class MethodContext
{
    /**
     * @param  ReflectionClass<object>  $reflection
     * @param  array<Node>  $fileStmts
     */
    public function __construct(
        public readonly ReflectionClass $reflection,
        public readonly ClassMethod $method,
        public readonly array $fileStmts,
    ) {}
}
