<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Concerns;

use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;

/**
 * Flatten an analysis's properties into an inline TypeScript object literal type.
 *
 * The single home for this: InlineArrayHandler and StaticCallHandler both render one, and neither
 * could reach the other's private copy before this trait.
 */
trait BuildsInlineObjectTypes
{
    /**
     * Flatten an analysis's properties into an inline TypeScript object literal type.
     *
     * Any enum-token substitution has to be applied to the properties before this is called.
     */
    protected function buildInlineObjectType(MethodAnalysis $analysis): string
    {
        if ($analysis->properties === []) {
            return 'Record<string, unknown>';
        }

        $parts = array_map(function (array $prop): string {
            $key = LaravelTsPublish::validJsObjectKey($prop['name']);

            return $prop['optional'] ? "{$key}?: {$prop['type']}" : "{$key}: {$prop['type']}";
        }, $analysis->properties);

        return '{ '.implode('; ', $parts).' }';
    }
}
