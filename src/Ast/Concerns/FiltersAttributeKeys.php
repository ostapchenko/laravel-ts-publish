<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Concerns;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Scalar\String_;

/**
 * The `only`/`except` filter vocabulary and the key list read off such a call's arguments.
 *
 * The single home for both: FiltersModelAttributes composes this trait for `$this->only([...])`,
 * RelationFilterHandler for `$this->relation->only([...])`. Stateless — no host state is read.
 */
trait FiltersAttributeKeys
{
    /**
     * The attribute filter methods supported by the analyzer.
     *
     * @return list<string>
     */
    protected function supportedAttributeFilters(): array
    {
        return ['only', 'except'];
    }

    /**
     * Extract string keys from a filter method call's arguments.
     *
     * Supports both the array form `->only(['id', 'name'])` and the variadic form `->only('id', 'name')`.
     *
     * @return list<string>|null
     */
    protected function extractFilterKeys(MethodCall|NullsafeMethodCall $call): ?array
    {
        if ($call->isFirstClassCallable()) {
            return null; // @codeCoverageIgnore
        }

        $args = $call->getArgs();

        if (count($args) < 1) {
            return null; // @codeCoverageIgnore
        }

        // Array form: ->only(['id', 'name'])
        if ($args[0]->value instanceof Array_) {
            /** @var list<string> $keys */
            $keys = [];

            foreach ($args[0]->value->items as $arrayItem) {
                if ($arrayItem->value instanceof String_) {
                    $keys[] = $arrayItem->value->value;
                }
            }

            return $keys !== [] ? $keys : null;
        }

        // Variadic form: ->only('id', 'name')
        /** @var list<string> $keys */
        $keys = [];

        foreach ($args as $arg) {
            if ($arg->value instanceof String_) {
                $keys[] = $arg->value->value;
            }
        }

        return $keys !== [] ? $keys : null;
    }
}
