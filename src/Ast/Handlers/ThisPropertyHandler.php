<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\ChecksPreserveKeys;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Analyzers\ResourceAnalysis;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\ResolvesEnumPropertyArgTypes;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use AbeTwoThree\LaravelTsPublish\Dtos\Contracts\Datable;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use AbeTwoThree\LaravelTsPublish\ModelAttributeResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;

/**
 * `$this->property` — resolved against the backing model, attributes before relations, matching
 * Laravel's `Model::__get`. Also carries `extractPropertiesFromArray()`, a plain-array-literal
 * property extractor still used by the analyzer's own merge()/mergeWhen() resolution.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 * @phpstan-import-type ResourcePropertyInfoList from MethodAnalysis
 * @phpstan-import-type ClassMapType from MethodAnalysis
 * @phpstan-import-type ImportMapType from MethodAnalysis
 * @phpstan-import-type InlineEnumFqcnsMap from MethodAnalysis
 * @phpstan-import-type InlineModelFqcnsMap from MethodAnalysis
 * @phpstan-import-type MultiEnumFqcnsMap from MethodAnalysis
 * @phpstan-import-type TypesImportMap from Datable
 */
final class ThisPropertyHandler implements ExpressionHandler
{
    use ChecksPreserveKeys;
    use InspectsAstNodes;
    use ResolvesEnumPropertyArgTypes;

    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [PropertyFetch::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        if ($this->isThisPropertyFetch($expr)) {
            return $this->analyzeThisProperty($expr, $scope);
        }

        return null;
    }

    /**
     * Extract properties and FQCNs from an array expression, e.g. for mergeWhen's second argument.
     *
     * Public: the analyzer's own resolveArrayOrClosureToProperties() (merge()/mergeWhen() resolution)
     * calls this directly, the same public-method bridge ClosureHandler::analyzeClosureUnion() uses.
     */
    public function extractPropertiesFromArray(Array_ $array, ExpressionEngine $engine, bool $optional = false): ResourceAnalysis
    {
        /** @var ResourcePropertyInfoList $properties */
        $properties = [];
        /** @var ClassMapType $enumResources */
        $enumResources = [];
        /** @var ClassMapType $nestedResources */
        $nestedResources = [];
        /** @var ClassMapType $directEnumFqcns */
        $directEnumFqcns = [];
        /** @var ClassMapType $modelFqcns */
        $modelFqcns = [];
        /** @var ImportMapType $customImports */
        $customImports = [];
        /** @var InlineEnumFqcnsMap $inlineEnumFqcns */
        $inlineEnumFqcns = [];
        /** @var InlineModelFqcnsMap $inlineModelFqcns */
        $inlineModelFqcns = [];
        /** @var MultiEnumFqcnsMap $multiEnumResourceFqcns */
        $multiEnumResourceFqcns = [];
        /** @var InlineEnumFqcnsMap $inlineEnumResourceFqcns */
        $inlineEnumResourceFqcns = [];

        foreach ($array->items as $item) {
            if ($item->key === null) {
                continue;
            }

            $keyName = $this->resolveKeyName($item->key);

            if ($keyName === null) {
                continue;
            }

            $result = $engine->resolve($item->value);

            $properties[] = [
                'name' => $keyName,
                'type' => $result['type'],
                'optional' => $optional || $result['optional'],
                'description' => '',
            ];

            $this->dispatchFqcnResults($keyName, $result, $enumResources, $directEnumFqcns, $nestedResources, $modelFqcns, $multiEnumResourceFqcns);

            foreach ($result['embeddedEnumFqcns'] ?? [] as $fqcn) {
                $inlineEnumFqcns[$keyName][] = $fqcn;
            }

            foreach ($result['embeddedEnumResourceFqcns'] ?? [] as $fqcn) {
                $inlineEnumResourceFqcns[$keyName][] = $fqcn;
            }

            foreach ($result['embeddedModelFqcns'] ?? [] as $fqcn) {
                $inlineModelFqcns[$keyName][] = $fqcn;
            }

            foreach ($result['customImports'] ?? [] as $path => $types) {
                $customImports[$path] = [...($customImports[$path] ?? []), ...$types];
            }
        }

        return new ResourceAnalysis(
            $properties,
            $enumResources,
            $nestedResources,
            customImports: $customImports,
            directEnumFqcns: $directEnumFqcns,
            modelFqcns: $modelFqcns,
            inlineEnumFqcns: $inlineEnumFqcns,
            inlineModelFqcns: $inlineModelFqcns,
            multiEnumResourceFqcns: $multiEnumResourceFqcns,
            inlineEnumResourceFqcns: $inlineEnumResourceFqcns,
        );
    }

    /**
     * Analyze $this->property — resolve the type from the backing model, attributes before relations
     * (matching Laravel's Model::__get).
     *
     * @return ValueExpressionResult
     */
    private function analyzeThisProperty(Expr $expr, AnalysisScope $scope): array
    {
        $result = ValueResult::unknown();

        /** @var PropertyFetch $expr */
        $propName = $expr->name instanceof Identifier ? $expr->name->toString() : null;

        if ($propName === null) {
            return $result; // @codeCoverageIgnore
        }

        if ($propName === 'collection' && $this->isResourceCollection($scope)) {
            return $this->analyzeCollectionProperty($scope);
        }

        $info = $this->resolveModelAttributeTypeInfo($propName, $scope);

        if ($info['type'] !== 'unknown') {
            $result = [
                ...$result,
                'type' => $info['type'],
            ];

            if ($info['enumFqcn'] !== null) {
                $result['directEnumFqcn'] = $info['enumFqcn'];
            }

            // A single-FQCN accessor needs no per-occurrence disambiguation; only a genuine union
            // needs its FQCNs threaded out here for aliasPropertyType() to consume per occurrence.
            if (count($info['classFqcns']) > 1) {
                $result['embeddedModelFqcns'] = $info['classFqcns'];
            }

            return $result;
        }

        $relationInfo = $this->resolveModelRelationTypeInfo($propName, $scope);

        if ($relationInfo['type'] !== 'unknown') {
            $result = [
                ...$result,
                'type' => $relationInfo['type'],
            ];

            if ($relationInfo['modelFqcn'] !== null) {
                $result['modelFqcn'] = $relationInfo['modelFqcn'];
            }

            if ($relationInfo['morphFqcns'] !== []) {
                $result['embeddedModelFqcns'] = $relationInfo['morphFqcns'];
            }

            return $result;
        }

        return $result;
    }

    /**
     * Determine whether the analyzed resource is a ResourceCollection subclass.
     *
     * Mirrors ResourceAstAnalyzer::isResourceCollection(); duplicated for $scope, not $this->scope —
     * still used elsewhere on the analyzer (analyze(), analyzePropertyChain()), so it stays there too.
     */
    private function isResourceCollection(AnalysisScope $scope): bool
    {
        return $scope->subjectReflection->isSubclassOf(ResourceCollection::class);
    }

    /**
     * Analyze $this->collection in a ResourceCollection: the singular resource type as an array,
     * or a keyed record when the collection preserves keys. The sole implementation now — its only
     * caller moved here with it, unlike isResourceCollection()/resolveSingularResourceClass() below.
     *
     * @return ValueExpressionResult
     */
    private function analyzeCollectionProperty(AnalysisScope $scope): array
    {
        $result = ValueResult::unknown();
        $singular = $this->resolveSingularResourceClass($scope);

        if ($singular === null) {
            return $result;
        }

        return [
            ...$result,
            'type' => $this->wrapCollectionElementType(LaravelTsPublish::resourceTypeName($singular), $scope->subjectReflection),
            'resourceFqcn' => $singular,
        ];
    }

    /**
     * Resolve the singular resource FQCN this ResourceCollection collects.
     * See InspectsAstNodes::resolveCollectedResourceClass() for the resolution order.
     *
     * Mirrors ResourceAstAnalyzer's own copy; duplicated for $scope.
     *
     * @return class-string<JsonResource>|null
     */
    private function resolveSingularResourceClass(AnalysisScope $scope): ?string
    {
        /** @var class-string $ownFqcn */
        $ownFqcn = $scope->subjectReflection->getName();

        return $this->resolveCollectedResourceClass($ownFqcn);
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
     * Dispatch FQCN results from a value expression into the tracking maps.
     *
     * Mirrors ResourceAstAnalyzer::dispatchFqcnResults(); duplicated because extractPropertiesFromArray()
     * moved here but dispatchFqcnResults() itself is still used by analyzeReturnArray() and another
     * analyzer method, so it stays defined there too.
     *
     * @param  ValueExpressionResult  $result
     * @param  ClassMapType  $enumResources
     * @param  ClassMapType  $directEnumFqcns
     * @param  ClassMapType  $nestedResources
     * @param  ClassMapType  $modelFqcns
     * @param  MultiEnumFqcnsMap  $multiEnumResourceFqcns
     */
    private function dispatchFqcnResults(
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
