<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\ChecksPreserveKeys;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use AbeTwoThree\LaravelTsPublish\ModelAttributeResolver;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use ReflectionClass;

/**
 * `new SomeResource(...)`, `new EnumResource($this->prop)`, and `new SomeCollection($this->items)`.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class NewResourceHandler implements ExpressionHandler
{
    use ChecksPreserveKeys;
    use InspectsAstNodes;

    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [New_::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        if ($expr instanceof New_) {
            return $this->analyzeNewResource($expr, $scope);
        }

        return null;
    }

    /**
     * Analyze `new SomeResource(...)` — resolve as a nested resource.
     *
     * @return ValueExpressionResult
     */
    private function analyzeNewResource(New_ $expr, AnalysisScope $scope): array
    {
        $result = ValueResult::unknown();

        if (! $expr->class instanceof Name) {
            return $result; // @codeCoverageIgnore
        }

        $className = $expr->class->toString();

        // Resolve `self`/`static` so `new self(...)` is treated identically to `new ClassName(...)`.
        if ($className === 'self' || $className === 'static') {
            $className = $scope->subjectReflection->getName();
        }

        // new EnumResource($this->prop)
        if ($this->isEnumResourceClass($className)) {
            $args = $expr->getArgs();

            if (count($args) >= 1) {
                return $this->resolveEnumFromPropertyArg($args[0]->value, $scope) ?? $result;
            }

            return $result;
        }

        // new SomeCollection($this->items) — resolve the collected element type. Must precede the
        // generic isResourceClass() branch below, for the same reason as in analyzeStaticCall().
        if (is_a($className, ResourceCollection::class, true)) {
            $collected = $this->resolveCollectedResourceClass($className);

            if ($collected !== null) {
                return [
                    ...$result,
                    'type' => $this->wrapCollectionElementType(LaravelTsPublish::resourceTypeName($collected), new ReflectionClass($className)),
                    'optional' => $this->hasConditionalNewArgument($expr),
                    'resourceFqcn' => $collected,
                ];
            }
        }

        if (! $this->isResourceClass($className)) {
            return $result; // @codeCoverageIgnore
        }

        $resourceName = LaravelTsPublish::resourceTypeName($className);
        $optional = $this->hasConditionalNewArgument($expr);

        /** @var class-string $className */
        return [
            ...$result,
            'type' => $resourceName,
            'optional' => $optional,
            'resourceFqcn' => $className,
        ];
    }

    /**
     * Resolve an enum type from a property-fetch expression (shared by EnumResource::make and new EnumResource).
     *
     * Handles `$this->property` against the resource's own model, and `$variable->property` against
     * `$closureRelationModelClass` inside a whenLoaded() closure.
     *
     * @return ValueExpressionResult|null
     */
    private function resolveEnumFromPropertyArg(Expr $argExpr, AnalysisScope $scope): ?array
    {
        $result = ValueResult::unknown();

        if (! $this->isThisPropertyFetch($argExpr)) {
            // A bare $variable may be a closure parameter bound to $this->prop by a when() condition.
            if ($argExpr instanceof Variable && is_string($argExpr->name)) {
                $boundExpr = $scope->closureParamExprBindings[$argExpr->name] ?? null;

                if ($boundExpr !== null) {
                    return $this->resolveEnumFromPropertyArg($boundExpr, $scope);
                }
            }

            // Handle $variable->property inside a whenLoaded closure.
            if (
                $argExpr instanceof PropertyFetch
                && $argExpr->var instanceof Variable
                && $argExpr->name instanceof Identifier
                && $scope->closureRelationModelClass !== null
            ) {
                $propName = $argExpr->name->toString();
                $tsInfo = resolve(ModelAttributeResolver::class)->resolveAttribute($scope->closureRelationModelClass, $propName);

                /** @var class-string|null $enumFqcn */
                $enumFqcn = $tsInfo['enumFqcns'][0] ?? null;

                if ($enumFqcn === null) {
                    return null;
                }

                // toTsType() on the FQCN directly yields the pure enum type, without the nullable
                // suffix appendNullable() adds from the DB column definition.
                $enumTsInfo = LaravelTsPublish::toTsType($enumFqcn);

                return [
                    ...$result,
                    'type' => $enumTsInfo['type'],
                    'enumFqcn' => $enumFqcn,
                ];
            }

            // `$this->resource->property` is equivalent to `$this->property`, since $this->resource
            // is the underlying model instance.
            if (
                $argExpr instanceof PropertyFetch
                && $argExpr->var instanceof PropertyFetch
                && $this->isThisPropertyFetch($argExpr->var)
                && $argExpr->var->name instanceof Identifier
                && $argExpr->var->name->toString() === 'resource'
                && $argExpr->name instanceof Identifier
            ) {
                $propName = $argExpr->name->toString();
                $info = $this->resolveModelAttributeTypeInfo($propName, $scope);

                if ($info['enumFqcn'] === null) {
                    return null;
                }

                return [
                    ...$result,
                    'type' => $info['type'],
                    'enumFqcn' => $info['enumFqcn'],
                ];
            }

            // Enum::staticMethod(...) or Enum::Case — resolved from the class name alone. parseAndResolveAst()
            // runs a NameResolver, so ->class is already the FQCN.
            $enumClassName = null;

            if ($argExpr instanceof StaticCall && $argExpr->class instanceof Name) {
                $enumClassName = $argExpr->class->toString();
            } elseif ($argExpr instanceof ClassConstFetch && $argExpr->class instanceof Name) {
                $enumClassName = $argExpr->class->toString();
            }

            if ($enumClassName !== null && enum_exists($enumClassName)) {
                $enumTsInfo = LaravelTsPublish::toTsType($enumClassName);

                return [
                    ...$result,
                    'type' => $enumTsInfo['type'],
                    'enumFqcn' => $enumClassName,
                ];
            }

            return null;
        }

        /** @var PropertyFetch $argExpr */
        $propName = $argExpr->name instanceof Identifier ? $argExpr->name->toString() : null;

        if ($propName === null) {
            return null; // @codeCoverageIgnore
        }

        $info = $this->resolveModelAttributeTypeInfo($propName, $scope);

        if ($info['enumFqcn'] === null) {
            return null;
        }

        return [
            ...$result,
            'type' => $info['type'],
            'enumFqcn' => $info['enumFqcn'],
        ];
    }

    /**
     * Resolve the TypeScript type, optional enum FQCN, and any class FQCNs for a model attribute.
     *
     * Bypasses ResolvesModelTypes's cached-property gate, which this per-call handler never
     * populates — calls the ModelAttributeResolver singleton directly instead; it caches per FQCN.
     *
     * @return array{type: string, enumFqcn: class-string|null, classFqcns: list<class-string>}
     */
    private function resolveModelAttributeTypeInfo(string $attributeName, AnalysisScope $scope): array
    {
        if ($scope->modelClass === null) {
            return ['type' => 'unknown', 'enumFqcn' => null, 'classFqcns' => []];
        }

        $tsInfo = resolve(ModelAttributeResolver::class)->resolveAttribute($scope->modelClass, $attributeName);

        /** @var class-string|null $enumFqcn */
        $enumFqcn = $tsInfo['enumFqcns'][0] ?? null;

        return ['type' => $tsInfo['type'], 'enumFqcn' => $enumFqcn, 'classFqcns' => $tsInfo['classFqcns']];
    }
}
