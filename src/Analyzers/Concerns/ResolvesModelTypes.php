<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Analyzers\Concerns;

use AbeTwoThree\LaravelTsPublish\Analyzers\ResourceAnalysis;
use AbeTwoThree\LaravelTsPublish\ModelAttributeResolver;
use AbeTwoThree\LaravelTsPublish\RelationNullable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionClass;

/**
 * Model type resolution helpers for the ResourceAstAnalyzer.
 *
 * Delegates the "accessor → cast → DB type" waterfall to ModelAttributeResolver
 * and provides thin wrappers that preserve the return shapes expected by callers.
 *
 * @phpstan-import-type ResourcePropertyInfoList from ResourceAnalysis
 * @phpstan-import-type ClassMapType from ResourceAnalysis
 * @phpstan-import-type InlineModelFqcnsMap from ResourceAnalysis
 * @phpstan-import-type AttributeInfo from \AbeTwoThree\LaravelTsPublish\Dtos\ModelInfo
 * @phpstan-import-type RelationInfo from \AbeTwoThree\LaravelTsPublish\Dtos\ModelInfo
 *
 * @phpstan-type ModelAttributeTypeResult = array{type: string, enumFqcn: class-string|null}
 * @phpstan-type ModelRelationTypeResult = array{type: string, modelFqcn: class-string<\Illuminate\Database\Eloquent\Model>|null, morphFqcns: list<class-string>}
 */
trait ResolvesModelTypes
{
    protected ?Model $modelInstance = null;

    protected ?RelationNullable $relationNullable = null;

    /** @var ReflectionClass<Model>|null */
    protected ?ReflectionClass $modelReflection = null;

    /** @var Collection<int, AttributeInfo>|null */
    protected ?Collection $modelAttributes = null;

    /** @var Collection<int, RelationInfo>|null */
    protected ?Collection $modelRelations = null;

    protected function loadModelInspectorData(): void
    {
        if ($this->modelClass === null || ! class_exists($this->modelClass)) {
            return;
        }

        $resolver = resolve(ModelAttributeResolver::class);

        $this->modelAttributes = $resolver->getAttributes($this->modelClass);
        $this->modelRelations = $resolver->getRelations($this->modelClass);
        $this->modelInstance = $resolver->getInstance($this->modelClass);
        $this->modelReflection = $resolver->getReflection($this->modelClass);
        $this->relationNullable = $resolver->getRelationNullable($this->modelClass);
    }

    /**
     * Resolve the TypeScript type and optional enum FQCN for a model attribute.
     *
     * @return ModelAttributeTypeResult
     */
    protected function resolveModelAttributeTypeInfo(string $attributeName): array
    {
        if ($this->modelClass === null || $this->modelAttributes === null) {
            return ['type' => 'unknown', 'enumFqcn' => null];
        }

        $tsInfo = resolve(ModelAttributeResolver::class)->resolveAttribute($this->modelClass, $attributeName);

        /** @var class-string|null $enumFqcn */
        $enumFqcn = $tsInfo['enumFqcns'][0] ?? null;

        return ['type' => $tsInfo['type'], 'enumFqcn' => $enumFqcn];
    }

    /**
     * @return ModelRelationTypeResult
     */
    protected function resolveModelRelationTypeInfo(string $relationName): array
    {
        if ($this->modelClass === null || $this->modelRelations === null) {
            return ['type' => 'unknown', 'modelFqcn' => null, 'morphFqcns' => []];
        }

        return resolve(ModelAttributeResolver::class)->resolveRelation($this->modelClass, $relationName);
    }

    /**
     * If $propName is an accessor attribute whose getter returns exactly one Eloquent Model
     * subclass, return its FQCN. Used by analyzeRelationFilter() as a fallback when the
     * property is not a database relation.
     *
     * @return class-string<Model>|null
     */
    protected function resolveAccessorModelFqcn(string $propName): ?string
    {
        if ($this->modelClass === null) {
            return null; // @codeCoverageIgnore
        }

        return resolve(ModelAttributeResolver::class)->resolveAccessorModelFqcn($this->modelClass, $propName);
    }

    /**
     * Return all Eloquent Model FQCNs that an accessor returns.
     * Used by analyzeRelationFilter() when the accessor union-types multiple models.
     *
     * @return list<class-string<Model>>
     */
    protected function resolveAccessorModelFqcns(string $propName): array
    {
        if ($this->modelClass === null) {
            return []; // @codeCoverageIgnore
        }

        return resolve(ModelAttributeResolver::class)->resolveAccessorModelFqcns($this->modelClass, $propName);
    }

    /**
     * Build a ResourceAnalysis from all model attributes and relations when the resource
     * delegates to JsonResource::toArray(). $excludeHidden is false only for only(), whose
     * property set is the caller's own keys — also gates the write-only mutator skip below.
     */
    protected function buildModelDelegatedAnalysis(bool $excludeHidden = true): ?ResourceAnalysis
    {
        if ($this->modelAttributes === null || $this->modelClass === null) {
            return null;
        }

        /** @var class-string $modelClass */
        $modelClass = $this->modelClass;
        $resolver = resolve(ModelAttributeResolver::class);
        $dropHidden = $excludeHidden && $resolver->excludeHiddenAttributes();
        $dbColumns = $resolver->databaseColumnNames($modelClass);

        /** @var ResourcePropertyInfoList $properties */
        $properties = [];
        /** @var ClassMapType $directEnumFqcns */
        $directEnumFqcns = [];
        /** @var ClassMapType $modelFqcns */
        $modelFqcns = [];
        /** @var InlineModelFqcnsMap $inlineModelFqcns */
        $inlineModelFqcns = [];

        foreach ($this->modelAttributes as $attr) {
            if ($dropHidden && $attr['hidden']) {
                continue;
            }

            // Only the implicit paths (except()/whole-model) may drop a write-only mutator: Model::only()
            // resolves through getAttribute(), which does return the key, so a named one must survive.
            $isOmittedMutator = $excludeHidden
                && ! in_array($attr['name'], $dbColumns, true)
                && $resolver->isOmittedMutator($modelClass, $attr['name']);

            if ($isOmittedMutator) {
                continue;
            }

            $info = $this->resolveModelAttributeTypeInfo($attr['name']);

            $properties[] = [
                'name' => $attr['name'],
                'type' => $info['type'],
                'optional' => false,
                'description' => '',
            ];

            if ($info['enumFqcn'] !== null) {
                $directEnumFqcns[$attr['name']] = $info['enumFqcn'];
            }
        }

        // Also include relations so they can be referenced by only()/except() filters
        if ($this->modelRelations !== null) {
            foreach ($this->modelRelations as $relation) {
                $info = $this->resolveModelRelationTypeInfo($relation['name']);

                if ($info['type'] !== 'unknown') {
                    $properties[] = [
                        'name' => $relation['name'],
                        'type' => $info['type'],
                        'optional' => false,
                        'description' => '',
                    ];

                    if ($info['modelFqcn'] !== null) {
                        $modelFqcns[$relation['name']] = $info['modelFqcn'];
                    }

                    // Self-keyed so every arm of a MorphTo union is imported; the per-property list
                    // additionally lets ResourceTransformer alias same-basename parents apart.
                    foreach ($info['morphFqcns'] as $morphFqcn) {
                        $modelFqcns[$morphFqcn] = $morphFqcn;
                        $inlineModelFqcns[$relation['name']][] = $morphFqcn;
                    }
                }
            }
        }

        return new ResourceAnalysis(
            properties: $properties,
            directEnumFqcns: $directEnumFqcns,
            modelFqcns: $modelFqcns,
            inlineModelFqcns: $inlineModelFqcns,
        );
    }

    /**
     * Resolve an inline TypeScript type for a filtered subset of a related model's attributes and relations.
     *
     * Used when a resource accesses `$this->relation->only([...])` or `->except([...])`.
     *
     * @param  class-string  $relatedModelClass
     * @param  list<string>  $keys
     * @return array{type: string, enumFqcns: list<class-string>, modelFqcns: list<class-string>}
     */
    protected function resolveFilteredRelationType(
        string $relatedModelClass,
        array $keys,
        bool $include,
    ): array {
        $result = ['type' => 'unknown', 'enumFqcns' => [], 'modelFqcns' => []];
        $resolver = resolve(ModelAttributeResolver::class);

        $relatedAttributes = $resolver->getAttributes($relatedModelClass);
        $relatedRelations = $resolver->getRelations($relatedModelClass);

        if ($relatedAttributes === null || $relatedRelations === null) {
            return $result; // @codeCoverageIgnore
        }

        if ($include) {
            $resolveKeys = $keys;
        } else {
            // except() derives its key list from every attribute, so it is implicit — unlike the
            // include branch above, which takes the caller's own keys and must not be filtered.
            $excludeHidden = $resolver->excludeHiddenAttributes();

            $attrNames = $relatedAttributes
                ->reject(fn (array $attr): bool => $excludeHidden && $attr['hidden'])
                ->pluck('name')
                ->all();
            $relationNames = $relatedRelations->pluck('name')->all();
            $resolveKeys = array_values(array_filter(
                array_merge($attrNames, $relationNames),
                fn (mixed $k) => ! in_array($k, $keys, true),
            ));
        }

        $parts = [];
        /** @var list<class-string> $collectedEnumFqcns */
        $collectedEnumFqcns = [];
        /** @var list<class-string> $collectedModelFqcns */
        $collectedModelFqcns = [];

        /** @var list<string> $resolveKeys */
        foreach ($resolveKeys as $key) {
            $attr = $relatedAttributes->firstWhere('name', $key);

            if ($attr !== null) {
                $tsInfo = $resolver->resolveAttribute($relatedModelClass, $key);

                if ($tsInfo['type'] !== 'unknown') {
                    $parts[] = $key.': '.$tsInfo['type'];

                    /** @var list<class-string> $enumFqcns */
                    $enumFqcns = $tsInfo['enumFqcns'];
                    array_push($collectedEnumFqcns, ...$enumFqcns);
                }

                continue;
            }

            // Relation
            $relationInfo = $resolver->resolveRelation($relatedModelClass, $key);

            if ($relationInfo['type'] !== 'unknown') {
                $parts[] = $key.': '.$relationInfo['type'];

                if ($relationInfo['modelFqcn'] !== null) {
                    /** @var class-string $relatedFqcn */
                    $relatedFqcn = $relationInfo['modelFqcn'];
                    $collectedModelFqcns[] = $relatedFqcn;
                }

                array_push($collectedModelFqcns, ...$relationInfo['morphFqcns']);
            }
        }

        $inlineType = $parts === [] ? 'unknown' : '{ '.implode('; ', $parts).' }';

        return [
            ...$result,
            'type' => $inlineType,
            'enumFqcns' => $collectedEnumFqcns,
            'modelFqcns' => $collectedModelFqcns,
        ];
    }
}
