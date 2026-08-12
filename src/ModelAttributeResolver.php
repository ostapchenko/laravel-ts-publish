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
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
     * Reverse morph-target map: sorted parent FQCNs that declare a MorphOne/MorphMany pointing at a
     * child. Keyed twice per relation found — once under `childFqcn|morphName` (the morph name read
     * from `getMorphType()`, e.g. `imageable`) so two differently-named morphTos on one child model
     * don't share a union, and once under the plain `childFqcn` as a legacy aggregate bucket used
     * when a child relation's own morph name can't be determined.
     *
     * @var array<string, list<class-string>>
     */
    protected array $morphTargetMap = [];

    /**
     * Per-FQCN cache of real database column names, keyed the same way as $contexts.
     *
     * @var array<class-string, list<string>>
     */
    protected array $dbColumnNamesCache = [];

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
                    $accessorInfo = $this->refineWithPropertyDocblock($ctx['reflection'], $attributeName, $accessorInfo);

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
        $snakeAttr = $snake === $attributeName ? null : $ctx['attributes']->firstWhere('name', $snake);

        // Only accessors: Eloquent camel-cases the key when looking for a mutator method, but never when
        // reading $attributes, so $order->placedAt on a plain placed_at column is null at runtime.
        if ($snakeAttr !== null && ($snakeAttr['cast'] === 'attribute' || $snakeAttr['cast'] === 'accessor')) {
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
     * Refine a vague resolved type using class-level @property/@property-read docblock tags
     * (Larastan/ide-helper convention): first the class/parent chain (child tags win), then each
     * of those classes' traits, recursively — a trait's own class docblock is consulted last.
     *
     * A tag naming a @phpstan-type/@phpstan-import-type alias expands to that alias's shape.
     * Public: ModelTransformer's mutator resolution also calls this for accessor-derived types,
     * since it resolves accessors directly rather than through resolveAttribute()'s own waterfall.
     *
     * @param  ReflectionClass<Model>  $reflection
     * @param  TypeScriptTypeInfo  $tsInfo
     * @return TypeScriptTypeInfo
     */
    public function refineWithPropertyDocblock(ReflectionClass $reflection, string $attributeName, array $tsInfo): array
    {
        if (! LaravelTsPublish::isVagueTsType($tsInfo['type'])) {
            return $tsInfo;
        }

        foreach ($this->propertyDocblockClasses($reflection) as $class) {
            $refined = $this->refineFromClassDocblock($class, $attributeName, $tsInfo);

            if ($refined !== null) {
                return $refined;
            }
        }

        return $tsInfo;
    }

    /**
     * Classes to search for an @property tag, in priority order: the class/parent chain first
     * (child wins), then every trait used anywhere in that chain, recursively.
     *
     * @param  ReflectionClass<Model>  $reflection
     * @return list<ReflectionClass<object>>
     */
    protected function propertyDocblockClasses(ReflectionClass $reflection): array
    {
        /** @var list<ReflectionClass<object>> $chain */
        $chain = [];

        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            $chain[] = $class;
        }

        $traits = [];

        foreach ($chain as $class) {
            $traits = [...$traits, ...$this->collectTraitsRecursively($class)];
        }

        return [...$chain, ...$traits];
    }

    /**
     * Every trait used by a class, and every trait those traits themselves use.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<ReflectionClass<object>>
     */
    protected function collectTraitsRecursively(ReflectionClass $class): array
    {
        $traits = [];

        foreach ($class->getTraits() as $trait) {
            $traits[] = $trait;
            $traits = [...$traits, ...$this->collectTraitsRecursively($trait)];
        }

        return $traits;
    }

    /**
     * Attempt a refinement from a single class's own (non-inherited) @property/@property-read
     * docblock tag. Null when the class has no usable tag for this attribute.
     *
     * Two forms are matched: the documented `@property Type $name` (`$` required, a trailing
     * description tolerated — the type capture excludes `$` entirely, so a genuine `$variable`
     * marker anywhere before the target name still blocks a run into a neighbouring tag or
     * description); and the non-standard, `$`-less `@property Type name` some vendor traits use,
     * accepted only in that exact undescribed form (name immediately followed by end of line or
     * the docblock terminator). The `$`-less type capture also excludes a bare space not
     * following a comma, so it can't span a natural-language description at all — without both
     * restrictions, a `$`-less tag's trailing description could have its last word mistaken for
     * the property name and resolved into a confidently wrong concrete type.
     *
     * @param  ReflectionClass<object>  $class
     * @param  TypeScriptTypeInfo  $current
     * @return TypeScriptTypeInfo|null
     */
    protected function refineFromClassDocblock(ReflectionClass $class, string $attributeName, array $current): ?array
    {
        $doc = $class->getDocComment();

        if ($doc === false) {
            return null;
        }

        $name = preg_quote($attributeName, '/');

        $matched = preg_match('/@property(?:-read)?[ \t]+([^$\r\n]+?)[ \t]+\$'.$name.'\b/', $doc, $m)
            || preg_match('/@property(?:-read)?[ \t]+((?:[^$\r\n \t,]|,[ \t]*)+)[ \t]+'.$name.'\b(?=[ \t]*(?:\r?\n|\*\/|$))/', $doc, $m);

        if (! $matched) {
            return null;
        }

        $useMap = LaravelTsPublish::parseFileUseStatements($class);
        $namespace = $class->getNamespaceName();

        $infos = [];

        foreach (LaravelTsPublish::splitPhpDocUnionType($m[1]) as $part) {
            $infos[] = LaravelTsPublish::resolveDocblockTypePartOrAlias($part, $useMap, $namespace, $class);
        }

        $resolved = count($infos) === 1 ? $infos[0] : LaravelTsPublish::mergeTypeScriptInfos($infos);

        return $this->isStrictlyMoreStructured($resolved['type'], $current['type']) ? $resolved : null;
    }

    /**
     * Whether a docblock-derived refinement is more structured than the type it would replace.
     *
     * A refinement that still names 'unknown' is only accepted when the type it replaces is
     * entirely vague (a bare untyped array/collection/object, optionally nullable) and the
     * refinement itself is not equally vague — e.g. `Record<string, unknown>` beats a bare
     * `unknown[]`, but neither beats the other.
     */
    protected function isStrictlyMoreStructured(string $candidate, string $current): bool
    {
        if (! LaravelTsPublish::isVagueTsType($candidate)) {
            return true;
        }

        return $this->isEntirelyVagueTsType($current) && ! $this->isEntirelyVagueTsType($candidate);
    }

    /**
     * Whether a type carries no structure at all, as opposed to merely containing 'unknown'
     * somewhere within an otherwise structured shape (e.g. `Record<string, unknown>`).
     */
    protected function isEntirelyVagueTsType(string $type): bool
    {
        $bare = str_ends_with($type, ' | null') ? substr($type, 0, -strlen(' | null')) : $type;

        return in_array($bare, ['unknown', 'unknown[]', 'object', 'unknown[] | Record<string, unknown>'], true);
    }

    /**
     * Resolve a relation name to its TypeScript type and related model FQCN.
     *
     * `morphFqcns` carries every parent a MorphTo may resolve to, because `modelFqcn` can only name one
     * and every token in the emitted union still needs an import.
     *
     * @param  class-string  $modelFqcn
     * @return array{type: string, modelFqcn: class-string<Model>|null, morphFqcns: list<class-string>}
     */
    public function resolveRelation(string $modelFqcn, string $relationName): array
    {
        $ctx = $this->resolveContext($modelFqcn);

        if ($ctx === null) {
            return ['type' => 'unknown', 'modelFqcn' => null, 'morphFqcns' => []];
        }

        $relation = $ctx['relations']->firstWhere('name', $relationName);

        if ($relation === null) {
            return ['type' => 'unknown', 'modelFqcn' => null, 'morphFqcns' => []];
        }

        $isMorphTo = $relation['type'] === 'MorphTo'
            || (str_ends_with($relation['type'], 'MorphTo') && ! str_ends_with($relation['type'], 'MorphToMany'));

        if ($isMorphTo) {
            $targets = $this->resolveMorphToTargets($modelFqcn, $relationName);

            return $this->buildMorphUnionInfo($targets, $relation, $ctx);
        }

        DependencyRecorder::recordClass($relation['related']);

        $relatedModel = class_basename($relation['related']);
        $containsMany = str_contains(strtolower($relation['type']), 'many');

        if ($containsMany) {
            return ['type' => $relatedModel.'[]', 'modelFqcn' => $relation['related'], 'morphFqcns' => []];
        }

        $type = $relatedModel;
        $nullableRelations = Config::boolean('ts-publish.models.nullable_relations');

        if ($nullableRelations && $ctx['relationNullable']->isNullable($relation)) {
            $type .= ' | null';
        }

        return ['type' => $type, 'modelFqcn' => $relation['related'], 'morphFqcns' => []];
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
     * Names of the model's real database columns, read straight from the schema.
     *
     * Mirrors ModelTransformer::transformColumns()'s $dbColumns exactly (same schema listing call) so a
     * caller that only trusts real columns — e.g. building a Pick<>/Omit<> reference — never names a key
     * the model interface doesn't also derive from that same source.
     *
     * @param  class-string  $modelFqcn
     * @return list<string>
     */
    public function databaseColumnNames(string $modelFqcn): array
    {
        if (isset($this->dbColumnNamesCache[$modelFqcn])) {
            return $this->dbColumnNamesCache[$modelFqcn];
        }

        $ctx = $this->resolveContext($modelFqcn);

        if ($ctx === null) {
            return [];
        }

        /** @var list<string> $columns */
        $columns = $ctx['instance']->getConnection()->getSchemaBuilder()->getColumnListing($ctx['instance']->getTable());

        return $this->dbColumnNamesCache[$modelFqcn] = $columns;
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
        /** @var array<string, list<class-string>> $map */
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

                // Written under both keys: the morph-name-specific bucket so two differently-named
                // morphTos on one child don't share a union, and the plain childFqcn bucket as the
                // legacy aggregate a child relation falls back to when its own name can't be read.
                $keys = [$childFqcn];
                $morphName = $this->relationMorphName($ctx['instance'], $relation['name']);

                if ($morphName !== null) {
                    $keys[] = $childFqcn.'|'.$morphName;
                }

                foreach ($keys as $key) {
                    if (! isset($map[$key])) {
                        $map[$key] = [];
                    }

                    if (! in_array($parentFqcn, $map[$key], true)) {
                        $map[$key][] = $parentFqcn;
                    }
                }
            }
        }

        // Sorted so the generated union type is stable across runs.
        foreach ($map as $key => $parents) {
            sort($parents);
            $map[$key] = $parents;
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
     * Return the list of parent model FQCNs that morphTo the given child model under the given
     * morph (relation) name — falling back to the legacy childFqcn-only bucket (every parent
     * regardless of name) when no parent declared a relation under that specific name.
     *
     * @param  class-string  $childModelFqcn
     * @return list<class-string>
     */
    public function getMorphToTargets(string $childModelFqcn, string $morphName): array
    {
        return $this->morphTargetMap[$childModelFqcn.'|'.$morphName]
            ?? $this->morphTargetMap[$childModelFqcn]
            ?? [];
    }

    /**
     * Resolve a MorphTo relation's target model FQCNs: a narrowing `@return MorphTo<X, ...>`
     * docblock generic first, then the reverse-relation map keyed by the relation's own morph
     * name. The single source of truth for MorphTo target resolution — both resolveRelation() and
     * ModelTransformer's relation pipeline call this, so a docblock generic is honored wherever a
     * MorphTo union is emitted, not just through resolveRelation()'s own callers.
     *
     * @param  class-string  $modelFqcn
     * @return list<class-string>
     */
    public function resolveMorphToTargets(string $modelFqcn, string $relationName): array
    {
        $docblockTargets = $this->morphToDocblockTargets($modelFqcn, $relationName);

        if ($docblockTargets !== []) {
            return $docblockTargets;
        }

        $ctx = $this->resolveContext($modelFqcn);

        if ($ctx === null) {
            return [];
        }

        $morphName = $this->relationMorphName($ctx['instance'], $relationName) ?? '';

        return $this->getMorphToTargets($modelFqcn, $morphName);
    }

    /**
     * Concrete Model subclasses named by a morphTo method's `@return MorphTo<X|Y, ...>` docblock
     * generic, resolved through the declaring class's — or, for a trait-provided relation, the
     * trait's — use-map via `methodDeclaringFileClass()` (the same resolution Task 7 introduced
     * for accessor/mutator docblocks). Bare `Model` and abstract targets yield `[]` so the caller
     * falls through to the reverse-relation map instead of importing a useless base class.
     *
     * @param  class-string  $modelFqcn
     * @return list<class-string<Model>>
     */
    protected function morphToDocblockTargets(string $modelFqcn, string $relationName): array
    {
        $reflection = $this->getReflection($modelFqcn);

        if ($reflection === null || ! $reflection->hasMethod($relationName)) {
            return [];
        }

        $method = $reflection->getMethod($relationName);
        $returnType = LaravelTsPublish::extractReturnTypeFromDocblock((string) $method->getDocComment());

        if ($returnType === null
            || ! preg_match('/^\\\\?(?:Illuminate\\\\Database\\\\Eloquent\\\\Relations\\\\)?MorphTo\s*<(.+)>$/s', trim($returnType), $m)
        ) {
            return [];
        }

        $declaringClass = LaravelTsPublish::methodDeclaringFileClass($method);
        $useMap = LaravelTsPublish::parseFileUseStatements($declaringClass);
        $namespace = $declaringClass->getNamespaceName();

        // Only the first generic argument names the target(s) — the second ($this, by Laravel's
        // own convention) carries no target information and is discarded here.
        $firstArg = trim(Str::before($m[1], ','));
        $targets = [];

        foreach (LaravelTsPublish::splitPhpDocUnionType($firstArg) as $part) {
            $fqcn = LaravelTsPublish::resolveDocblockTypeName(trim($part), $useMap, $namespace);

            if (! class_exists($fqcn) || ! is_a($fqcn, Model::class, true) || $fqcn === Model::class) {
                return [];
            }

            if ((new ReflectionClass($fqcn))->isAbstract()) {
                return [];
            }

            /** @var class-string<Model> $fqcn */
            $targets[] = $fqcn;
        }

        return $targets;
    }

    /**
     * Build a MorphTo relation's resolveRelation()-shaped result from a resolved target list —
     * shared by the docblock-generic and reverse-map branches so both apply nullability and
     * morphFqcns identically.
     *
     * @param  list<class-string>  $targets
     * @param  RelationInfo  $relation
     * @param  array{relationNullable: RelationNullable, ...}  $ctx
     * @return array{type: string, modelFqcn: class-string<Model>|null, morphFqcns: list<class-string>}
     */
    protected function buildMorphUnionInfo(array $targets, array $relation, array $ctx): array
    {
        $type = $targets !== []
            ? implode(' | ', array_map(class_basename(...), $targets))
            : 'unknown';

        $nullableRelations = Config::boolean('ts-publish.models.nullable_relations');

        if ($nullableRelations && $ctx['relationNullable']->isNullable($relation)) {
            $type .= ' | null';
        }

        return ['type' => $type, 'modelFqcn' => null, 'morphFqcns' => $targets];
    }

    /**
     * The morph "name" (e.g. 'imageable') for a MorphTo/MorphOne/MorphMany relation, read from its
     * getMorphType() column ('imageable_type' minus the '_type' suffix). Building an Eloquent
     * relation queries nothing — addConstraints() only appends to the query builder — so invoking
     * it on an unpersisted instance is safe; any failure degrades to null so callers fall back to
     * the legacy per-child bucket instead of surfacing an exception.
     */
    protected function relationMorphName(Model $instance, string $relationName): ?string
    {
        try {
            $relation = $instance->{$relationName}();
        } catch (Throwable) {
            return null;
        }

        if (! $relation instanceof MorphTo && ! $relation instanceof MorphOneOrMany) {
            return null; // @codeCoverageIgnore
        }

        $morphType = $relation->getMorphType();

        return str_ends_with($morphType, '_type') ? substr($morphType, 0, -5) : $morphType;
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
