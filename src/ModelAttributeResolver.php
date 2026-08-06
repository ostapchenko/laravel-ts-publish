<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish;

use AbeTwoThree\LaravelTsPublish\Cache\DependencyRecorder;
use AbeTwoThree\LaravelTsPublish\Concerns\ResolvesAccessorType;
use AbeTwoThree\LaravelTsPublish\Dtos\ModelInfo;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Centralized model attribute → TypeScript type resolver.
 *
 * Encapsulates the "accessor → cast → DB type" waterfall that was previously
 * duplicated across ResolvesModelTypes, ResourceAstAnalyzer, and ModelTransformer.
 *
 * Registered as a singleton so ModelInspector results are cached per FQCN for the
 * duration of the publish run.
 *
 * @phpstan-import-type TypeScriptTypeInfo from \AbeTwoThree\LaravelTsPublish\LaravelTsPublish
 * @phpstan-import-type AttributeInfo from ModelInfo
 * @phpstan-import-type RelationInfo from ModelInfo
 */
class ModelAttributeResolver
{
    use ResolvesAccessorType;

    /**
     * Per-FQCN cache of inspected model context.
     *
     * @var array<class-string, array{
     *     instance: Model,
     *     reflection: ReflectionClass<Model>,
     *     attributes: Collection<int, AttributeInfo>,
     *     relations: Collection<int, RelationInfo>,
     *     relationNullable: RelationNullable,
     * }>
     */
    protected array $contexts = [];

    /**
     * Map from a morphable child model FQCN to the sorted list of parent
     * model FQCNs that declare a MorphOne/MorphMany pointing at it.
     *
     * Built once via buildMorphTargetMap() before model processing begins.
     *
     * @var array<class-string, list<class-string>>
     */
    protected array $morphTargetMap = [];

    /**
     * Resolve the full TypeScriptTypeInfo for a model attribute through the
     * accessor → cast → DB type waterfall.
     *
     * @param  class-string  $modelFqcn
     * @return TypeScriptTypeInfo
     */
    public function resolveAttribute(string $modelFqcn, string $attributeName): array
    {
        $empty = LaravelTsPublish::emptyTypeScriptInfo();
        $ctx = $this->resolveContext($modelFqcn);

        if ($ctx === null) {
            return $empty;
        }

        $attr = $ctx['attributes']->firstWhere('name', $attributeName);

        if ($attr === null) {
            return $empty;
        }

        $cast = $attr['cast'];

        // 1. Accessor — resolve via reflection to get the getter's return type
        if (($cast === 'attribute' || $cast === 'accessor')) {
            try {
                $accessorInfo = $this->resolveAccessorType($attributeName, $ctx['instance'], $ctx['reflection']);

                if ($accessorInfo['type'] !== 'unknown') {
                    return $this->appendNullable($accessorInfo, $attr['nullable']);
                }
            } catch (Throwable) { // @codeCoverageIgnore
                // Fall through to cast/DB type
            }
        }

        // 2. Regular cast (enum, date, json, etc.)
        if ($cast !== null && $cast !== '' && $cast !== 'attribute' && $cast !== 'accessor') {
            $tsInfo = LaravelTsPublish::toTsType($cast);

            $tsInfo = $this->refineWithPropertyDocblock($ctx['reflection'], $attributeName, $tsInfo);

            return $this->appendNullable($tsInfo, $attr['nullable']);
        }

        // 3. DB column type
        if ($attr['type'] === null || $attr['type'] === '') {
            return $empty;
        }

        $tsInfo = LaravelTsPublish::toTsType($attr['type']);

        if ($tsInfo['type'] === 'unknown') {
            return $empty; // @codeCoverageIgnore
        }

        $tsInfo = $this->refineWithPropertyDocblock($ctx['reflection'], $attributeName, $tsInfo);

        return $this->appendNullable($tsInfo, $attr['nullable']);
    }

    /**
     * Refine a vague resolved type using class-level @property/@property-read
     * docblock tags (Larastan/ide-helper convention). Child class tags win.
     *
     * @param  ReflectionClass<Model>  $reflection
     * @param  TypeScriptTypeInfo  $tsInfo
     * @return TypeScriptTypeInfo
     */
    protected function refineWithPropertyDocblock(ReflectionClass $reflection, string $attributeName, array $tsInfo): array
    {
        if (! str_contains($tsInfo['type'], 'unknown') && $tsInfo['type'] !== 'object') {
            return $tsInfo;
        }

        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            $doc = $class->getDocComment();

            if ($doc === false) {
                continue;
            }

            // The type capture is a non-greedy `[^$\r\n]+?` rather than `\S+`:
            // PHPDoc generics routinely contain internal spaces (e.g.
            // `array<string, string>`, per this method's own docblock example
            // above), so a whitespace-stopped `\S+` can't span them. Excluding
            // `$` and line breaks from the capture (instead of the simpler
            // `.+?`) closes two related holes: `.+?` can capture straight
            // through a *different* tag's own `$variable` and past its
            // description text (e.g. `@property string $label Falls back to
            // the $otherColumn value` would otherwise let a query for
            // `otherColumn` capture "string $label Falls back to the" as its
            // type), and `\s+` matches newlines, so a type-less `@property`
            // line could bleed into whatever text follows it. `[ \t]+`
            // (instead of `\s+`) around the capture keeps the whole match
            // confined to one line for the same reason.
            if (! preg_match(
                '/@property(?:-read)?[ \t]+([^$\r\n]+?)[ \t]+\$'.preg_quote($attributeName, '/').'\b/',
                $doc,
                $m,
            )) {
                continue;
            }

            $useMap = LaravelTsPublish::parseFileUseStatements($class);
            $namespace = $class->getNamespaceName();

            $infos = [];

            foreach (LaravelTsPublish::splitPhpDocUnionType($m[1]) as $part) {
                $infos[] = LaravelTsPublish::resolveDocblockTypePart($part, $useMap, $namespace);
            }

            $resolved = count($infos) === 1 ? $infos[0] : LaravelTsPublish::mergeTypeScriptInfos($infos);

            if (! str_contains($resolved['type'], 'unknown')) {
                return $resolved;
            }
        }

        return $tsInfo;
    }

    /**
     * Resolve a relation name to its TypeScript type and related model FQCN.
     *
     * @param  class-string  $modelFqcn
     * @return array{type: string, modelFqcn: class-string<Model>|null}
     */
    public function resolveRelation(string $modelFqcn, string $relationName): array
    {
        $ctx = $this->resolveContext($modelFqcn);

        if ($ctx === null) {
            return ['type' => 'unknown', 'modelFqcn' => null];
        }

        $relation = $ctx['relations']->firstWhere('name', $relationName);

        if ($relation === null) {
            return ['type' => 'unknown', 'modelFqcn' => null];
        }

        $isMorphTo = $relation['type'] === 'MorphTo'
            || (str_ends_with($relation['type'], 'MorphTo') && ! str_ends_with($relation['type'], 'MorphToMany'));

        if ($isMorphTo) {
            $targets = $this->getMorphToTargets($modelFqcn);

            if ($targets !== []) {
                $type = implode(' | ', array_map(class_basename(...), $targets));
            } else {
                $type = 'unknown';
            }

            $nullableRelations = Config::boolean('ts-publish.models.nullable_relations');

            if ($nullableRelations && $ctx['relationNullable']->isNullable($relation)) {
                $type .= ' | null';
            }

            return ['type' => $type, 'modelFqcn' => null];
        }

        DependencyRecorder::recordClass($relation['related']);

        $relatedModel = class_basename($relation['related']);
        $containsMany = str_contains(strtolower($relation['type']), 'many');

        if ($containsMany) {
            return ['type' => $relatedModel.'[]', 'modelFqcn' => $relation['related']];
        }

        $type = $relatedModel;
        $nullableRelations = Config::boolean('ts-publish.models.nullable_relations');

        if ($nullableRelations && $ctx['relationNullable']->isNullable($relation)) {
            $type .= ' | null';
        }

        return ['type' => $type, 'modelFqcn' => $relation['related']];
    }

    /**
     * Resolve the return type of a method (instance or static) on a model.
     *
     * @param  class-string  $modelFqcn
     * @return TypeScriptTypeInfo
     */
    public function resolveMethodReturnType(string $modelFqcn, string $methodName): array
    {
        try {
            /** @var ReflectionClass<Model> $reflection */
            $reflection = new ReflectionClass($modelFqcn);

            return LaravelTsPublish::methodOrDocblockReturnTypes($reflection, $methodName);
        } catch (Throwable) {
            return LaravelTsPublish::emptyTypeScriptInfo();
        }
    }

    /**
     * If an attribute is an accessor whose getter returns exactly one Eloquent
     * Model subclass, return its FQCN. Used as a fallback when the property is
     * not a database relation.
     *
     * @param  class-string  $modelFqcn
     * @return class-string<Model>|null
     */
    public function resolveAccessorModelFqcn(string $modelFqcn, string $attributeName): ?string
    {
        $fqcns = $this->resolveAccessorModelFqcns($modelFqcn, $attributeName);

        return count($fqcns) === 1 ? $fqcns[0] : null;
    }

    /**
     * Return all Eloquent Model FQCNs that an accessor's getter may return.
     * Used when an accessor is typed as Attribute<ModelA|ModelB, never> and a
     * partial filter (->only / ->except) is applied to the result.
     *
     * @param  class-string  $modelFqcn
     * @return list<class-string<Model>>
     */
    public function resolveAccessorModelFqcns(string $modelFqcn, string $attributeName): array
    {
        $ctx = $this->resolveContext($modelFqcn);

        if ($ctx === null) {
            return [];
        }

        $attr = $ctx['attributes']->firstWhere('name', $attributeName);

        if ($attr === null || ($attr['cast'] !== 'attribute' && $attr['cast'] !== 'accessor')) {
            return []; // @codeCoverageIgnore
        }

        try {
            $accessorInfo = $this->resolveAccessorType($attributeName, $ctx['instance'], $ctx['reflection']);

            /** @var list<class-string<Model>> $fqcns */
            $fqcns = array_values(array_filter(
                $accessorInfo['classFqcns'],
                fn (string $fqcn) => is_a($fqcn, Model::class, true),
            ));

            // An accessor-returned model reached here is inlined into a resource's
            // output when the resource applies ->only()/->except() to it (its
            // column/cast types become an anonymous object shape). Its source file
            // is therefore a real cache dependency — record it, mirroring how
            // resolveRelation() records related models.
            foreach ($fqcns as $fqcn) {
                DependencyRecorder::recordClass($fqcn);
            }

            return $fqcns;
        } catch (Throwable) { // @codeCoverageIgnore
            return []; // @codeCoverageIgnore
        }
    }

    /**
     * @param  class-string  $modelFqcn
     * @return Collection<int, AttributeInfo>|null
     */
    public function getAttributes(string $modelFqcn): ?Collection
    {
        return $this->resolveContext($modelFqcn)['attributes'] ?? null;
    }

    /**
     * @param  class-string  $modelFqcn
     * @return Collection<int, RelationInfo>|null
     */
    public function getRelations(string $modelFqcn): ?Collection
    {
        return $this->resolveContext($modelFqcn)['relations'] ?? null;
    }

    /**
     * @param  class-string  $modelFqcn
     */
    public function getRelationNullable(string $modelFqcn): ?RelationNullable
    {
        return $this->resolveContext($modelFqcn)['relationNullable'] ?? null;
    }

    /**
     * @param  class-string  $modelFqcn
     */
    public function getInstance(string $modelFqcn): ?Model
    {
        return $this->resolveContext($modelFqcn)['instance'] ?? null;
    }

    /**
     * @param  class-string  $modelFqcn
     * @return ReflectionClass<Model>|null
     */
    public function getReflection(string $modelFqcn): ?ReflectionClass
    {
        return $this->resolveContext($modelFqcn)['reflection'] ?? null;
    }

    /**
     * Scan all configured models' relations to build the morph target map.
     *
     * For each model that declares a MorphOne or MorphMany relation, the
     * related (child) model is recorded as being morphable by the declaring
     * (parent) model.  The resulting map lets getMorphToTargets() resolve
     * MorphTo relations to precise union types instead of `unknown`.
     *
     * @param  list<class-string>  $modelFqcns  All model FQCNs that will be processed.
     */
    public function buildMorphTargetMap(array $modelFqcns): void
    {
        /** @var array<class-string, list<class-string>> $map */
        $map = [];

        foreach ($modelFqcns as $parentFqcn) {
            $ctx = $this->resolveContext($parentFqcn);

            if ($ctx === null) {
                continue;
            }

            foreach ($ctx['relations'] as $relation) {
                if (! $this->isMorphParentRelation($parentFqcn, $relation)) {
                    continue;
                }

                $childFqcn = $relation['related'];

                DependencyRecorder::recordClass($childFqcn);

                if (! isset($map[$childFqcn])) {
                    $map[$childFqcn] = [];
                }

                if (! in_array($parentFqcn, $map[$childFqcn], true)) {
                    $map[$childFqcn][] = $parentFqcn;
                }
            }
        }

        // Sort each target list alphabetically for deterministic output.
        foreach ($map as $childFqcn => $parents) {
            sort($parents);
            $map[$childFqcn] = $parents;
        }

        $this->morphTargetMap = $map;
    }

    /**
     * A relation counts as a morph parent when its inspected type is exactly
     * MorphOne/MorphMany, or when the declaring method's return type is a
     * subclass of either (custom relation classes like MorphOneFile).
     *
     * @param  class-string  $parentFqcn
     * @param  RelationInfo  $relation
     */
    protected function isMorphParentRelation(string $parentFqcn, array $relation): bool
    {
        if ($relation['type'] === 'MorphOne' || $relation['type'] === 'MorphMany') {
            return true;
        }

        $reflection = $this->getReflection($parentFqcn);

        if ($reflection === null || ! $reflection->hasMethod($relation['name'])) {
            return false;
        }

        $returnType = $reflection->getMethod($relation['name'])->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return false;
        }

        $fqcn = $returnType->getName();

        return is_a($fqcn, MorphOne::class, true)
            || is_a($fqcn, MorphMany::class, true);
    }

    /**
     * Return the list of parent model FQCNs that morphTo the given child model.
     *
     * @param  class-string  $childModelFqcn
     * @return list<class-string>
     */
    public function getMorphToTargets(string $childModelFqcn): array
    {
        return $this->morphTargetMap[$childModelFqcn] ?? [];
    }

    /**
     * Lazily build and cache the model context (instance, reflection, attributes, relations).
     *
     * @param  class-string  $modelFqcn
     * @return array{instance: Model, reflection: ReflectionClass<Model>, attributes: Collection<int, AttributeInfo>, relations: Collection<int, RelationInfo>, relationNullable: RelationNullable}|null
     */
    protected function resolveContext(string $modelFqcn): ?array
    {
        if (isset($this->contexts[$modelFqcn])) {
            return $this->contexts[$modelFqcn];
        }

        if (! class_exists($modelFqcn)) {
            return null;
        }

        try {
            /** @var Model $instance */
            $instance = resolve($modelFqcn);

            $data = resolve(ModelInspector::class)->inspect($modelFqcn);

            /** @var Collection<int, AttributeInfo> $attributes */
            $attributes = $data->attributes;

            /** @var ReflectionClass<Model> $reflection */
            $reflection = new ReflectionClass($modelFqcn);

            $this->contexts[$modelFqcn] = [
                'instance' => $instance,
                'reflection' => $reflection,
                'attributes' => $attributes,
                'relations' => $data->relations,
                'relationNullable' => new RelationNullable($instance, $attributes),
            ];

            return $this->contexts[$modelFqcn];
        } catch (Throwable) { // @codeCoverageIgnore
            return null; // @codeCoverageIgnore
        }
    }

    /**
     * Append ' | null' to a TypeScriptTypeInfo's type when nullable and not already present.
     *
     * @param  TypeScriptTypeInfo  $tsInfo
     * @return TypeScriptTypeInfo
     */
    protected function appendNullable(array $tsInfo, ?bool $nullable): array
    {
        if ($nullable && ! str_contains($tsInfo['type'], 'null')) {
            $tsInfo['type'] .= ' | null';
        }

        return $tsInfo;
    }
}
