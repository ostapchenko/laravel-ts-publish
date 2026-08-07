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
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Resolves model attributes and relations to TypeScript types via the accessor → cast → DB type waterfall.
 *
 * Registered as a singleton so inspected model contexts are cached per FQCN for the whole publish run.
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
     * Morphable child model FQCN → sorted parent FQCNs that declare a MorphOne/MorphMany pointing at it.
     *
     * @var array<class-string, list<class-string>>
     */
    protected array $morphTargetMap = [];

    /**
     * Resolve a model attribute's TypeScript type through the accessor → cast → DB type waterfall.
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
            return $this->resolveAttributeFallbacks($modelFqcn, $ctx, $attributeName);
        }

        $cast = $attr['cast'];

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

        if ($cast !== null && $cast !== '' && $cast !== 'attribute' && $cast !== 'accessor') {
            $tsInfo = LaravelTsPublish::toTsType($cast);

            $tsInfo = $this->refineWithPropertyDocblock($ctx['reflection'], $attributeName, $tsInfo);

            return $this->appendNullable($tsInfo, $attr['nullable']);
        }

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
     * Resolve names that are not literal attributes: camelCase aliases, and withCount()/withExists() virtuals.
     *
     * The snake_case fallback is tried first because a same-named accessor is a declared type, while the
     * suffix fallbacks are a fixed number/boolean guess.
     *
     * @param  class-string  $modelFqcn
     * @param  array{attributes: Collection<int, AttributeInfo>, relations: Collection<int, RelationInfo>, ...}  $ctx
     * @return TypeScriptTypeInfo
     */
    protected function resolveAttributeFallbacks(string $modelFqcn, array $ctx, string $attributeName): array
    {
        $empty = LaravelTsPublish::emptyTypeScriptInfo();

        // Guarded on the snake form being a literal attribute, so the recursive call always lands on
        // resolveAttribute()'s exact-match branch and can never re-enter this method.
        $snake = Str::snake($attributeName);

        if ($snake !== $attributeName && $ctx['attributes']->firstWhere('name', $snake) !== null) {
            return $this->resolveAttribute($modelFqcn, $snake);
        }

        // Requires a matching relation, so a real column ending in "_count" is never guessed at here.
        foreach (['_count' => 'number', '_exists' => 'boolean'] as $suffix => $tsType) {
            if (! str_ends_with($attributeName, $suffix)) {
                continue;
            }

            $base = Str::camel(substr($attributeName, 0, -strlen($suffix)));

            if ($ctx['relations']->firstWhere('name', $base) !== null) {
                return [...$empty, 'type' => $tsType];
            }
        }

        return $empty;
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

            // PHPDoc generics contain spaces (`array<string, string>`), so the type capture cannot be `\S+`.
            // Excluding `$` and newlines instead of using `.+?` stops it running through a neighbouring
            // tag's own `$variable` and description text.
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
     * The single Eloquent Model FQCN an accessor's getter returns, or null when it is not exactly one.
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
     * Return every Eloquent Model FQCN that an accessor's getter may return.
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

            // These models get inlined into a resource by ->only()/->except(), so their files are real
            // cache dependencies.
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
     * Scan every model's MorphOne/MorphMany relations to build the child → parents morph target map.
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

        // Sorted so the generated union type is stable across runs.
        foreach ($map as $childFqcn => $parents) {
            sort($parents);
            $map[$childFqcn] = $parents;
        }

        $this->morphTargetMap = $map;
    }

    /**
     * Whether a relation is a morph parent, counting custom subclasses of MorphOne/MorphMany.
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
