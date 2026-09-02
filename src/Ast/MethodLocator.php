<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use ReflectionClass;

/**
 * Finds a method's ClassMethod AST node, memoized, recording every parsed file as a cache dependency.
 */
class MethodLocator
{
    /** @var array<string, MethodContext|null> */
    protected array $located = [];

    public function __construct(protected AstParser $parser) {}

    /**
     * Locate a method in the class's OWN file only — an inherited method is deliberately a miss,
     * which is how callers detect delegation/inheritance cases.
     *
     * Callers pass names straight from route action strings, so the AST is searched for the name as
     * declared rather than as spelled; PHP dispatches either, and a mismatch would silently type nothing.
     */
    public function locateOwn(string $class, string $method): ?MethodContext
    {
        return $this->memo('own:'.$class.'::'.strtolower($method), function () use ($class, $method): ?MethodContext {
            if (! class_exists($class)) {
                return null;
            }

            /** @var ReflectionClass<object> $reflection */
            $reflection = new ReflectionClass($class);

            if (! $reflection->hasMethod($method)) {
                return null;
            }

            $file = $reflection->getFileName();

            if ($file === false) {
                return null;
            }

            $declaredName = $reflection->getMethod($method)->getName();

            return $this->findIn($reflection, (string) $file, $declaredName, caseSensitive: true);
        });
    }

    /**
     * Locate a method wherever it is declared (class, trait, or parent), matching case-insensitively
     * to mirror PHP's own method dispatch.
     */
    public function locate(string $class, string $method): ?MethodContext
    {
        return $this->memo('any:'.$class.'::'.strtolower($method), function () use ($class, $method): ?MethodContext {
            if (! class_exists($class)) {
                return null;
            }

            /** @var ReflectionClass<object> $reflection */
            $reflection = new ReflectionClass($class);

            if (! $reflection->hasMethod($method)) {
                return null;
            }

            $file = $reflection->getMethod($method)->getFileName();

            if ($file === false) {
                return null;
            }

            return $this->findIn($reflection, (string) $file, $method, caseSensitive: false);
        });
    }

    /**
     * Run the finder over a parsed file for the named ClassMethod with a non-null body.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    protected function findIn(ReflectionClass $reflection, string $file, string $method, bool $caseSensitive): ?MethodContext
    {
        $stmts = $this->parser->parseFile($file);

        /** @var ClassMethod|null $node */
        $node = (new NodeFinder)->findFirst($stmts, function (Node $node) use ($method, $caseSensitive): bool {
            return $node instanceof ClassMethod && ($caseSensitive
                ? $node->name->toString() === $method
                : strcasecmp($node->name->toString(), $method) === 0);
        });

        if (! $node instanceof ClassMethod || $node->stmts === null) {
            return null;
        }

        return new MethodContext($reflection, $node, $stmts);
    }

    /**
     * Memoize both hits and misses so repeated lookups never re-parse.
     *
     * @param  callable(): ?MethodContext  $resolve
     */
    protected function memo(string $key, callable $resolve): ?MethodContext
    {
        if (array_key_exists($key, $this->located)) {
            return $this->located[$key];
        }

        return $this->located[$key] = $resolve();
    }
}
