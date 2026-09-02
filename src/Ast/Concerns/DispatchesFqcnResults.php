<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Concerns;

use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;

/**
 * Fan a value expression's FQCN channels out into the caller's per-key tracking maps.
 *
 * The single home for this: the analyzer's own return-array walk and ThisPropertyHandler's
 * array-literal extractor both build the same maps from the same result shape.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 * @phpstan-import-type ClassMapType from MethodAnalysis
 * @phpstan-import-type MultiEnumFqcnsMap from MethodAnalysis
 */
trait DispatchesFqcnResults
{
    /**
     * Dispatch FQCN results from a value expression into the tracking maps.
     *
     * @param  ValueExpressionResult  $result
     * @param  ClassMapType  $enumResources
     * @param  ClassMapType  $directEnumFqcns
     * @param  ClassMapType  $nestedResources
     * @param  ClassMapType  $modelFqcns
     * @param  MultiEnumFqcnsMap  $multiEnumResourceFqcns
     */
    protected function dispatchFqcnResults(
        string $keyName,
        array $result,
        array &$enumResources,
        array &$directEnumFqcns,
        array &$nestedResources,
        array &$modelFqcns,
        array &$multiEnumResourceFqcns = [],
    ): void {
        if (isset($result['enumFqcn'])) {
            $enumResources[$keyName] = $result['enumFqcn'];
        }

        if (isset($result['directEnumFqcn'])) {
            $directEnumFqcns[$keyName] = $result['directEnumFqcn'];
        }

        if (isset($result['multiEnumResourceFqcns'])) {
            $multiEnumResourceFqcns[$keyName] = $result['multiEnumResourceFqcns'];
        }

        if (isset($result['resourceFqcn'])) {
            $nestedResources[$keyName] = $result['resourceFqcn'];
        }

        if (isset($result['modelFqcn'])) {
            $modelFqcns[$keyName] = $result['modelFqcn'];
        }

        // Embedded FQCNs from inline relation filter types (e.g. $this->post->only([...])).
        // Using FQCN as both key and value: ResourceTransformer only reads the value, never the key.
        foreach ($result['embeddedEnumFqcns'] ?? [] as $fqcn) {
            $directEnumFqcns[$fqcn] = $fqcn;
        }

        foreach ($result['embeddedModelFqcns'] ?? [] as $fqcn) {
            $modelFqcns[$fqcn] = $fqcn;
        }

        foreach ($result['embeddedResourceFqcns'] ?? [] as $fqcn) {
            $nestedResources[$fqcn] = $fqcn;
        }
    }
}
