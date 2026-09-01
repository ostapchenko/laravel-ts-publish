<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use AbeTwoThree\LaravelTsPublish\Dtos\Contracts\Datable;
use AbeTwoThree\LaravelTsPublish\ModelAttributeResolver;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;

/**
 * `$this->relation->only([...])`/`->except([...])` and Laravel's `map` HigherOrderCollectionProxy
 * filter (`$var->map->only([...])`/`->except([...])`) — relation/collection attribute filters.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 * @phpstan-import-type TypesImportMap from Datable
 */
final class RelationFilterHandler implements ExpressionHandler
{
    use InspectsAstNodes;

    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [MethodCall::class, NullsafeMethodCall::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        // $this->relation->only([...]) or $this->relation?->only([...])
        if (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall)
            && $expr->name instanceof Identifier
            && in_array($expr->name->toString(), $this->supportedAttributeFilters(), true)
            && $expr->var instanceof PropertyFetch
            && $expr->var->var instanceof Variable
            && $expr->var->var->name === 'this'
        ) {
            return $this->analyzeRelationFilter($expr, $scope);
        }

        // $var->map->only([...]) / ->except([...]) — Laravel's HigherOrderCollectionProxy on `map`:
        // call the filter method on every element and collect the results. The PropertyFetch here is
        // literally named 'map' (the proxy), never 'this' — disjoint from the relation guard above.
        if (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall)
            && $expr->name instanceof Identifier
            && in_array($expr->name->toString(), $this->supportedAttributeFilters(), true)
            && $expr->var instanceof PropertyFetch
            && $expr->var->name instanceof Identifier
            && $expr->var->name->toString() === 'map'
        ) {
            return $this->analyzeMapProxyFilter($expr, $scope);
        }

        return null;
    }

    /**
     * The attribute filter methods supported by the analyzer.
     *
     * Mirrors FiltersModelAttributes::supportedAttributeFilters(); duplicated because that trait's
     * other methods (analyzeThisAttributeFilter() etc.) need buildModelDelegatedAnalysis(), which
     * this per-call handler has no access to — see extractFilterKeys() below for the same reason.
     *
     * @return list<string>
     */
    private function supportedAttributeFilters(): array
    {
        return ['only', 'except'];
    }

    /**
     * Extract string keys from a filter method call's arguments.
     *
     * Supports both the array form `->only(['id', 'name'])` and the variadic form `->only('id', 'name')`.
     *
     * Mirrors FiltersModelAttributes::extractFilterKeys(); duplicated (stateless, verbatim body) —
     * that trait stays on the analyzer for analyzeThisAttributeFilter()'s own use.
     *
     * @return list<string>|null
     */
    private function extractFilterKeys(MethodCall|NullsafeMethodCall $call): ?array
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

    /**
     * Analyze `$this->relation->only([...])` or `$this->relation?->only([...])`.
     *
     * @return ValueExpressionResult
     */
    private function analyzeRelationFilter(MethodCall|NullsafeMethodCall $call, AnalysisScope $scope): array
    {
        $result = ValueResult::unknown();

        $nullable = $call instanceof NullsafeMethodCall;
        $methodName = $call->name instanceof Identifier ? $call->name->toString() : null;

        if ($methodName === null) {
            return $result; // @codeCoverageIgnore
        }

        /** @var PropertyFetch $varExpr */
        $varExpr = $call->var;
        $propName = $varExpr->name instanceof Identifier ? $varExpr->name->toString() : null;

        if ($propName === null) {
            return $result; // @codeCoverageIgnore
        }

        $relationInfo = $this->resolveModelRelationTypeInfo($propName, $scope);
        $modelFqcn = $relationInfo['modelFqcn'] ?? $this->resolveAccessorModelFqcn($propName, $scope);

        if ($modelFqcn === null) {
            // Try the multi-model accessor path (e.g. Attribute<ModelA|ModelB, never>).
            $modelFqcns = $this->resolveAccessorModelFqcns($propName, $scope);

            if ($modelFqcns === []) {
                return $result; // @codeCoverageIgnore
            }

            $keys = $this->extractFilterKeys($call);

            if ($keys === null || $keys === []) {
                return $result; // @codeCoverageIgnore
            }

            $include = $methodName === 'only';

            /** @var list<string> $inlineTypes */
            $inlineTypes = [];
            /** @var list<class-string> $embeddedEnumFqcns */
            $embeddedEnumFqcns = [];
            /** @var list<class-string> $embeddedModelFqcns */
            $embeddedModelFqcns = [];
            /** @var TypesImportMap $embeddedCustomImports */
            $embeddedCustomImports = [];
            /** @var list<class-string<Model>> $seenFqcns */
            $seenFqcns = [];

            // Dedupe on the arm's own FQCN, not the rendered string: relationFilterModelReference()
            // renders class_basename($fqcn), so two different FQCNs sharing a basename (e.g. two
            // "User" models) would otherwise render identically and the second arm would be dropped.
            foreach ($modelFqcns as $fqcn) {
                if (in_array($fqcn, $seenFqcns, true)) {
                    continue;
                }

                $seenFqcns[] = $fqcn;

                // Every filter key is a plain DB column: reference the arm's own model interface so
                // its #[TsCasts]/@property refinements stay authoritative, same as the single-model path.
                $modelReference = $this->relationFilterModelReference($fqcn, $keys, $include);

                if ($modelReference !== null) {
                    $inlineTypes[] = $modelReference;
                    $embeddedModelFqcns[] = $fqcn;

                    continue;
                }

                $filterResult = $this->resolveFilteredRelationType($fqcn, $keys, $include);

                if ($filterResult['type'] === 'unknown') {
                    continue;
                }

                $inlineTypes[] = $filterResult['type'];
                array_push($embeddedEnumFqcns, ...$filterResult['enumFqcns']);
                array_push($embeddedModelFqcns, ...$filterResult['modelFqcns']);

                foreach ($filterResult['customImports'] as $path => $names) {
                    $embeddedCustomImports[$path] = [...($embeddedCustomImports[$path] ?? []), ...$names];
                }
            }

            if ($inlineTypes === []) {
                return $result; // @codeCoverageIgnore
            }

            $inlineType = implode(' | ', $inlineTypes);

            if ($nullable) {
                $inlineType .= ' | null';
            }

            return [
                ...$result,
                'type' => $inlineType,
                'embeddedEnumFqcns' => array_values(array_unique($embeddedEnumFqcns)),
                // Never deduped: aliasPropertyType() walks this list positionally against left-to-right
                // occurrences of each basename in $inlineType, so a real repeat must survive as a repeat.
                'embeddedModelFqcns' => $embeddedModelFqcns,
                'customImports' => $embeddedCustomImports,
            ];
        }

        $keys = $this->extractFilterKeys($call);

        if ($keys === null || $keys === []) {
            return $result; // @codeCoverageIgnore
        }

        $include = $methodName === 'only';

        // Every filter key is a plain DB column: reference the emitted model interface directly so its
        // #[TsCasts]/@property refinements stay authoritative instead of being re-derived and lost.
        $modelReference = $this->relationFilterModelReference($modelFqcn, $keys, $include);

        if ($modelReference !== null) {
            $type = $modelReference;

            if (str_ends_with($relationInfo['type'], '[]')) {
                $type .= '[]';
            }

            if ($nullable) {
                $type .= ' | null';
            }

            return [
                ...$result,
                'type' => $type,
                'modelFqcn' => $modelFqcn,
            ];
        }

        $filterResult = $this->resolveFilteredRelationType($modelFqcn, $keys, $include);
        $inlineType = $filterResult['type'];

        // Wrap in array suffix when the relation is a *-many type (HasMany, BelongsToMany, etc.)
        if (str_ends_with($relationInfo['type'], '[]') && $inlineType !== 'unknown') {
            $inlineType .= '[]';
        }

        if ($nullable && $inlineType !== 'unknown') {
            $inlineType .= ' | null';
        }

        return [
            ...$result,
            'type' => $inlineType,
            'embeddedEnumFqcns' => $filterResult['enumFqcns'],
            'embeddedModelFqcns' => $filterResult['modelFqcns'],
            'customImports' => $filterResult['customImports'],
        ];
    }

    /**
     * Analyze `$var->map->only([...])` / `$var->map->except([...])` — Laravel's HigherOrderCollectionProxy
     * on `map`, which runs the filter method against every element and collects the results.
     *
     * @return ValueExpressionResult
     */
    private function analyzeMapProxyFilter(MethodCall|NullsafeMethodCall $call, AnalysisScope $scope): array
    {
        $result = ValueResult::unknown();

        $methodName = $call->name instanceof Identifier ? $call->name->toString() : null;

        if ($methodName === null) {
            return $result; // @codeCoverageIgnore
        }

        /** @var PropertyFetch $mapFetch */
        $mapFetch = $call->var;
        $elementModel = $this->resolveMapProxyElementModel($mapFetch->var, $scope);

        if ($elementModel === null) {
            return $result;
        }

        $keys = $this->extractFilterKeys($call);

        if ($keys === null || $keys === []) {
            return $result;
        }

        $filterResult = $this->resolveFilteredRelationType($elementModel, $keys, $methodName === 'only');

        if ($filterResult['type'] === 'unknown') {
            return $result;
        }

        $inlineType = $this->arrayWrapType($filterResult['type']);

        if ($call instanceof NullsafeMethodCall) {
            $inlineType .= ' | null';
        }

        return [
            ...$result,
            'type' => $inlineType,
            'embeddedEnumFqcns' => $filterResult['enumFqcns'],
            'embeddedModelFqcns' => $filterResult['modelFqcns'],
            'customImports' => $filterResult['customImports'],
        ];
    }

    /**
     * Resolve the element model behind a `->map` proxy receiver: a whenLoaded to-many closure
     * parameter, or `$this->relation` itself. A singular relation's bound variable is not a
     * collection and must not match, so it returns null rather than guessing a shape.
     *
     * The binding is never invalidated by a reassignment inside the closure (e.g.
     * `$members = $members->flatMap(...)` before `$members->map(...)`), so a reassigned receiver
     * still resolves against the original relation's element model — an accepted approximation.
     *
     * Mirrors ResourceAstAnalyzer::resolveMapProxyElementModel(); duplicated for $scope — it is still
     * used elsewhere on the analyzer (analyzeVariableMapCall()), so it stays defined there too.
     *
     * @return class-string<Model>|null
     */
    private function resolveMapProxyElementModel(Expr $receiver, AnalysisScope $scope): ?string
    {
        if ($receiver instanceof Variable
            && is_string($receiver->name)
            && isset($scope->varCollectionBindings[$receiver->name])
        ) {
            return $scope->varCollectionBindings[$receiver->name]['modelFqcn'];
        }

        if ($receiver instanceof PropertyFetch
            && $this->isThisPropertyFetch($receiver)
            && $receiver->name instanceof Identifier
        ) {
            $relationInfo = $this->resolveModelRelationTypeInfo($receiver->name->toString(), $scope);

            if (str_ends_with($relationInfo['type'], '[]') && $relationInfo['modelFqcn'] !== null) {
                return $relationInfo['modelFqcn'];
            }
        }

        return null;
    }

    /**
     * Build a Pick<Model, …> reference when every filter key is a declared model column.
     *
     * Targets the bare model interface: except() iterates only $this->getAttributes(), so relations and
     * accessors never surface. Picks the complement, not Omit<>, to stay independent of the active template.
     *
     * @param  class-string<Model>  $modelFqcn
     * @param  list<string>  $keys
     */
    private function relationFilterModelReference(string $modelFqcn, array $keys, bool $include): ?string
    {
        $resolver = resolve(ModelAttributeResolver::class);
        $columns = $resolver->publishedColumnNames($modelFqcn);

        if ($columns === []) {
            return null; // @codeCoverageIgnore
        }

        foreach ($keys as $key) {
            if (! in_array($key, $columns, true)) {
                return null;
            }
        }

        $picked = $include ? $keys : array_values(array_diff($columns, $keys));

        if ($picked === []) {
            return 'Pick<'.class_basename($modelFqcn).', never>';
        }

        $quoted = implode(' | ', array_map(fn (string $k): string => "'".$k."'", $picked));

        return 'Pick<'.class_basename($modelFqcn).', '.$quoted.'>';
    }

    /**
     * Resolve an inline TypeScript type for a filtered subset of a related model's attributes and relations.
     *
     * Used when a resource accesses `$this->relation->only([...])` or `->except([...])`.
     *
     * Mirrors ResolvesModelTypes::resolveFilteredRelationType(); duplicated verbatim — it is already
     * stateless (no $this->scope reference), so no signature change was needed.
     *
     * @param  class-string  $relatedModelClass
     * @param  list<string>  $keys
     * @return array{type: string, enumFqcns: list<class-string>, modelFqcns: list<class-string>, customImports: TypesImportMap}
     */
    private function resolveFilteredRelationType(
        string $relatedModelClass,
        array $keys,
        bool $include,
    ): array {
        $result = ['type' => 'unknown', 'enumFqcns' => [], 'modelFqcns' => [], 'customImports' => []];
        $resolver = resolve(ModelAttributeResolver::class);

        $relatedAttributes = $resolver->getAttributes($relatedModelClass);
        $relatedRelations = $resolver->getRelations($relatedModelClass);

        if ($relatedAttributes === null || $relatedRelations === null) {
            return $result; // @codeCoverageIgnore
        }

        if ($include) {
            $resolveKeys = $keys;
        } else {
            // HasAttributes::except() iterates getAttributes() only — never $this->relations, and never a
            // get-only accessor, which mergeAttributeFromAttributeCasts() refuses to merge back. Columns only.
            $excludeHidden = $resolver->excludeHiddenAttributes();
            $dbColumns = $resolver->databaseColumnNames($relatedModelClass);

            $attrNames = $relatedAttributes
                ->reject(fn (array $attr): bool => $excludeHidden && $attr['hidden'])
                ->pluck('name')
                ->filter(fn (mixed $name): bool => in_array($name, $dbColumns, true))
                ->all();

            $resolveKeys = array_values(array_filter(
                $attrNames,
                fn (mixed $k) => ! in_array($k, $keys, true),
            ));
        }

        $parts = [];
        /** @var list<class-string> $collectedEnumFqcns */
        $collectedEnumFqcns = [];
        /** @var list<class-string> $collectedModelFqcns */
        $collectedModelFqcns = [];
        /** @var TypesImportMap $collectedCustomImports */
        $collectedCustomImports = [];

        /** @var list<string> $resolveKeys */
        foreach ($resolveKeys as $key) {
            $attr = $relatedAttributes->firstWhere('name', $key);

            if ($attr !== null) {
                $tsInfo = $resolver->resolveAttribute($relatedModelClass, $key);

                // The except branch yields columns now, so in practice this gate is only()'s: a write-only
                // mutator with no getter and no docblock Get has no shape to emit, unlike a getter-backed one.
                if ($tsInfo['type'] !== 'unknown' || ! $resolver->isOmittedMutator($relatedModelClass, $key)) {
                    $parts[] = $key.': '.$tsInfo['type'];

                    /** @var list<class-string> $enumFqcns */
                    $enumFqcns = $tsInfo['enumFqcns'];
                    array_push($collectedEnumFqcns, ...$enumFqcns);

                    // Sibling of the enumFqcns collection above: an inlined attribute can itself
                    // reference another model or a #[TsType(import:)] alias, both needed to compile.
                    /** @var list<class-string> $classFqcns */
                    $classFqcns = $tsInfo['classFqcns'];
                    array_push($collectedModelFqcns, ...$classFqcns);

                    foreach ($tsInfo['customImports'] as $path => $names) {
                        $collectedCustomImports[$path] = [...($collectedCustomImports[$path] ?? []), ...$names];
                    }
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
            'customImports' => $collectedCustomImports,
        ];
    }

    /**
     * Resolve a `$this->{name}` property as a model relation, in ModelAttributeResolver::resolveRelation()'s
     * {type, modelFqcn, morphFqcns} shape — a to-many relation's type ends in '[]'.
     *
     * Mirrors ResourceAstAnalyzer's own override of this name; duplicated for $scope, not $this->scope —
     * same already-deferred cluster as ConditionalMethodHandler's and ToResourceHandler's copies.
     *
     * @return array{type: string, modelFqcn: class-string<Model>|null, morphFqcns: list<class-string>}
     */
    private function resolveModelRelationTypeInfo(string $name, AnalysisScope $scope): array
    {
        if ($scope->modelClass === null) {
            return ['type' => 'unknown', 'modelFqcn' => null, 'morphFqcns' => []];
        }

        return resolve(ModelAttributeResolver::class)->resolveRelation($scope->modelClass, $name);
    }

    /**
     * If $propName is an accessor attribute whose getter returns exactly one Eloquent Model
     * subclass, return its FQCN. Used as a fallback when the property is not a database relation.
     *
     * Mirrors ResolvesModelTypes::resolveAccessorModelFqcn(); duplicated for $scope — it has no
     * other caller on the analyzer once analyzeRelationFilter() moves.
     *
     * @return class-string<Model>|null
     */
    private function resolveAccessorModelFqcn(string $propName, AnalysisScope $scope): ?string
    {
        if ($scope->modelClass === null) {
            return null; // @codeCoverageIgnore
        }

        return resolve(ModelAttributeResolver::class)->resolveAccessorModelFqcn($scope->modelClass, $propName);
    }

    /**
     * Return all Eloquent Model FQCNs that an accessor returns, used when the accessor union-types
     * multiple models.
     *
     * Mirrors ResolvesModelTypes::resolveAccessorModelFqcns(); duplicated for $scope — same reason
     * as resolveAccessorModelFqcn() above.
     *
     * @return list<class-string<Model>>
     */
    private function resolveAccessorModelFqcns(string $propName, AnalysisScope $scope): array
    {
        if ($scope->modelClass === null) {
            return []; // @codeCoverageIgnore
        }

        return resolve(ModelAttributeResolver::class)->resolveAccessorModelFqcns($scope->modelClass, $propName);
    }

    /**
     * Suffix a type with `[]`, parenthesizing a union or intersection first: TypeScript binds `[]`
     * tighter than both, so `A & B[]` parses as `A & (B[])`, not `(A & B)[]`.
     *
     * Mirrors ResourceAstAnalyzer::arrayWrapType(); duplicated for the same reason StaticCallHandler's
     * own copy already is — it is still used elsewhere on the analyzer.
     */
    private function arrayWrapType(string $type): string
    {
        return str_contains($type, '|') || str_contains($type, '&') ? '('.$type.')[]' : $type.'[]';
    }
}
