<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Analyzers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\ChecksPreserveKeys;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\FiltersModelAttributes;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\ResolvesModelTypes;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\ResolvesRelatedModelTypes;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\ExpressionDispatcher;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\BinaryOpHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\CastHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ClassConstantHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ClosureHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\CoalesceHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ConditionalMethodHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ConstFetchHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\FirstClassCallableHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\KnownFunctionCallHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\NewResourceHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ScalarHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\StaticCallHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ToResourceHandler;
use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;
use AbeTwoThree\LaravelTsPublish\Ast\MethodLocator;
use AbeTwoThree\LaravelTsPublish\Ast\ReflectedTypeAcceptor;
use AbeTwoThree\LaravelTsPublish\Ast\SubjectMethodTypeResolver;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use AbeTwoThree\LaravelTsPublish\Attributes\TsCasts;
use AbeTwoThree\LaravelTsPublish\Cache\DependencyRecorder;
use AbeTwoThree\LaravelTsPublish\Concerns\ResolvesClassNames;
use AbeTwoThree\LaravelTsPublish\Dtos\Contracts\Datable;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use AbeTwoThree\LaravelTsPublish\ModelAttributeResolver;
use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Expression as ExpressionStmt;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Analyzes a JsonResource's toArray() body to extract property names, types, and optional markers via AST.
 *
 * @phpstan-import-type ResourcePropertyInfoList from MethodAnalysis
 * @phpstan-import-type ClassMapType from MethodAnalysis
 * @phpstan-import-type ImportMapType from MethodAnalysis
 * @phpstan-import-type InlineEnumFqcnsMap from MethodAnalysis
 * @phpstan-import-type InlineModelFqcnsMap from MethodAnalysis
 * @phpstan-import-type MultiEnumFqcnsMap from MethodAnalysis
 * @phpstan-import-type TypeScriptTypeInfo from \AbeTwoThree\LaravelTsPublish\LaravelTsPublish
 * @phpstan-import-type TypesImportMap from Datable
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 *
 * @phpstan-type InlineSpreadArm = array{fqcn: class-string, isModel: bool, isCollection: bool}
 */
class ResourceAstAnalyzer implements ExpressionEngine
{
    use ChecksPreserveKeys;
    use FiltersModelAttributes;
    use InspectsAstNodes;
    use ResolvesClassNames;
    use ResolvesModelTypes;
    use ResolvesRelatedModelTypes;

    /** Carries the subject reflection, model class, and all closure/spread bindings; see AnalysisScope. */
    protected AnalysisScope $scope;

    /** Built once per instance by dispatcher(), so the handler-candidate memo survives across dispatches. */
    protected ?ExpressionDispatcher $dispatcher = null;

    /**
     * Create an analyzer for a resource class and its optional backing model.
     *
     * @param  ReflectionClass<JsonResource>  $resourceReflection
     * @param  class-string<Model>|null  $modelClass
     */
    public function __construct(
        ReflectionClass $resourceReflection,
        ?string $modelClass = null,
    ) {
        $this->scope = new AnalysisScope(self::genericReflection($resourceReflection->getName()), $modelClass);

        if ($this->scope->modelClass !== null) {
            $this->loadModelInspectorData();
        }
    }

    /**
     * `ReflectionClass`'s template is invariant, so a caller's `ReflectionClass<JsonResource>` cannot
     * be assigned into `AnalysisScope`'s `<object>` slot; re-reflecting by name erases the generic.
     *
     * @param  class-string  $className
     * @return ReflectionClass<object>
     */
    private static function genericReflection(string $className): ReflectionClass
    {
        return new ReflectionClass($className);
    }

    /**
     * Analyze the resource's toArray() and return the resulting property/type analysis.
     */
    public function analyze(): ResourceAnalysis
    {
        if ($this->scope->modelClass !== null) {
            DependencyRecorder::recordClass($this->scope->modelClass);
        }

        $context = resolve(MethodLocator::class)->locateOwn($this->scope->subjectReflection->getName(), 'toArray');
        $toArrayMethod = $context?->method;

        if ($toArrayMethod === null || $toArrayMethod->stmts === null) {
            $inherited = $this->analyzeParentToArray();

            // An empty result means no ancestor declared a toArray() either, so keep delegating.
            if ($inherited !== null && $inherited->properties !== []) {
                return $inherited;
            }

            if ($this->isResourceCollection()) {
                return $this->buildCollectionDelegatedAnalysis();
            }

            return $this->buildModelDelegatedAnalysis() ?? new ResourceAnalysis;
        }

        $finder = new NodeFinder;

        $this->scope->instanceOfWrappedClass = $this->resolveInstanceOfType($toArrayMethod, $finder);

        $this->collectLocalVarBindings($toArrayMethod->stmts);

        $branchAnalysis = $this->analyzeAllReturnBranches($toArrayMethod->stmts);

        if ($branchAnalysis !== null) {
            if ($this->scope->subjectReflection->hasMethod('toArray')) {
                $this->applyTsCastsFromMethod($this->scope->subjectReflection->getMethod('toArray'), $branchAnalysis);
            }

            return $branchAnalysis;
        }

        // Fallback: find the first Return_ for non-array returns (parent::toArray, $this->only, etc.)
        $returnStmt = $finder->findFirst($toArrayMethod->stmts, function (Node $node): bool {
            return $node instanceof Return_;
        });

        if (! $returnStmt instanceof Return_ || $returnStmt->expr === null) {
            return new ResourceAnalysis; // @codeCoverageIgnore
        }

        if ($this->isParentToArrayCall($returnStmt->expr)) {
            return $this->analyzeParentToArray() ?? $this->buildModelDelegatedAnalysis() ?? new ResourceAnalysis;
        }

        // return $this->only([...]) or return $this->except([...])
        if ($returnStmt->expr instanceof MethodCall) {
            $filtered = $this->analyzeThisAttributeFilter($returnStmt->expr);

            if ($filtered !== null) {
                return $filtered;
            }

            // return $this->someMethod() — resolve it the same way an array-literal spread would.
            if ($this->hasThisReceiver($returnStmt->expr) && $returnStmt->expr->name instanceof Identifier) {
                return $this->analyzeThisMethodSpread($returnStmt->expr->name->toString()) ?? new ResourceAnalysis;
            }

            return new ResourceAnalysis;
        }

        return new ResourceAnalysis;
    }

    /**
     * Resolve a single expression to its TypeScript type. ExpressionEngine entry point for handlers.
     *
     * @return ValueExpressionResult
     */
    public function resolve(Expr $expr): array
    {
        return $this->analyzeValueExpression($expr);
    }

    /**
     * Spread-analyze a named method on the subject under analysis. ExpressionEngine entry point
     * for a handler that resolves a self-returning chain onto a non-preserving method body.
     */
    public function spreadAnalysis(string $methodName): ?ResourceAnalysis
    {
        return $this->analyzeThisMethodSpread($methodName);
    }

    /**
     * Record top-level `$var = expr;` statements so property values referencing those variables resolve.
     *
     * Skips variables written more than once — this flat list can't tell which write is live at a
     * given return branch, so binding one risks a wrong-but-plausible type instead of unknown.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    protected function collectLocalVarBindings(array $stmts): void
    {
        /** @var array<string, int> $writeCounts */
        $writeCounts = [];

        foreach ($this->collectWrittenVariableNames($stmts) as $name) {
            $writeCounts[$name] = ($writeCounts[$name] ?? 0) + 1;
        }

        foreach ($stmts as $stmt) {
            if ($stmt instanceof ExpressionStmt
                && $stmt->expr instanceof Assign
                && $stmt->expr->var instanceof Variable
                && is_string($stmt->expr->var->name)
                && ($writeCounts[$stmt->expr->var->name] ?? 0) === 1
            ) {
                $this->scope->localVarBindings[$stmt->expr->var->name] = $stmt->expr->expr;
            }
        }

        $this->bindForeachLoopVariables($stmts);
    }

    /**
     * Bind a top-level `foreach ($this->{manyRelation} as $item)`'s value variable to the relation's
     * element model, for the whole method — mirrors `collectLocalVarBindings()`'s method-wide scope.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    protected function bindForeachLoopVariables(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Foreach_
                || ! $stmt->valueVar instanceof Variable
                || ! is_string($stmt->valueVar->name)
                || ! $stmt->expr instanceof PropertyFetch
                || ! $this->isThisPropertyFetch($stmt->expr)
                || ! $stmt->expr->name instanceof Identifier
            ) {
                continue;
            }

            $relationInfo = $this->resolveModelRelationTypeInfo($stmt->expr->name->toString());

            if (str_ends_with($relationInfo['type'], '[]') && $relationInfo['modelFqcn'] !== null) {
                $this->scope->varModelBindings[$stmt->valueVar->name] = $relationInfo['modelFqcn'];
            }
        }
    }

    /**
     * Collect every local variable name written anywhere in a statement tree (writes, mutations,
     * foreach targets, closure by-ref uses).
     *
     * By-reference call arguments are a known gap — the callee's signature isn't statically knowable.
     *
     * @param  array<Node>  $stmts
     * @return list<string>
     */
    protected function collectWrittenVariableNames(array $stmts): array
    {
        $finder = new NodeFinder;

        $writeNodes = $finder->find(
            $stmts,
            fn (Node $node): bool => $node instanceof Assign
                || $node instanceof AssignRef
                || $node instanceof AssignOp
                || $node instanceof PreInc
                || $node instanceof PostInc
                || $node instanceof PreDec
                || $node instanceof PostDec
                || $node instanceof Foreach_
                || $node instanceof ClosureExpr,
        );

        /** @var list<string> $names */
        $names = [];

        foreach ($writeNodes as $node) {
            /** @var list<Expr> $targets */
            $targets = [];

            if ($node instanceof AssignRef) {
                $targets[] = $node->var;
                $targets[] = $node->expr;
            } elseif ($node instanceof Assign || $node instanceof AssignOp
                || $node instanceof PreInc || $node instanceof PostInc
                || $node instanceof PreDec || $node instanceof PostDec) {
                $targets[] = $node->var;
            } elseif ($node instanceof Foreach_) {
                $targets[] = $node->valueVar;

                if ($node->keyVar !== null) {
                    $targets[] = $node->keyVar;
                }
            } elseif ($node instanceof ClosureExpr) {
                foreach ($node->uses as $use) {
                    if ($use->byRef) {
                        $targets[] = $use->var;
                    }
                }
            }

            foreach ($targets as $target) {
                $vars = $finder->find(
                    $target,
                    fn (Node $n): bool => $n instanceof Variable && is_string($n->name),
                );

                foreach ($vars as $var) {
                    if ($var instanceof Variable && is_string($var->name)) {
                        $names[] = $var->name;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Analyze a returned array literal into properties, spreads, and FQCN tracking maps.
     */
    protected function analyzeReturnArray(Array_ $array): ResourceAnalysis
    {
        $analysis = new ResourceAnalysis;

        foreach ($array->items as $item) {
            // Handle ...parent::toArray($request) spread
            if ($item->key === null && $item->unpack && $this->isParentToArrayCall($item->value)) {
                $parentAnalysis = $this->analyzeParentToArray();

                if ($parentAnalysis !== null) {
                    $analysis->merge($parentAnalysis);
                }

                continue;
            }

            // Handle ...$this->only([...]) or ...$this->except([...]) spread
            if ($item->key === null && $item->unpack
                && $item->value instanceof MethodCall
                && $item->value->var instanceof Variable
                && $item->value->var->name === 'this'
                && $item->value->name instanceof Identifier
                && in_array($item->value->name->toString(), $this->supportedAttributeFilters(), true)) {
                $filterAnalysis = $this->analyzeThisAttributeFilter($item->value);

                if ($filterAnalysis !== null) {
                    $analysis->merge($filterAnalysis);
                }

                continue;
            }

            // Handle ...$this->method() spread (e.g., trait methods returning arrays)
            if ($item->key === null && $item->unpack
                && $item->value instanceof MethodCall
                && $item->value->var instanceof Variable
                && $item->value->var->name === 'this'
                && $item->value->name instanceof Identifier) {
                $spreadAnalysis = $this->analyzeThisMethodSpread($item->value->name->toString());

                if ($spreadAnalysis !== null) {
                    $analysis->merge($spreadAnalysis);
                }

                continue;
            }

            // Handle ...functionCall() spread (bare trait method calls without $this->)
            if ($item->key === null && $item->unpack && $item->value instanceof FuncCall) {
                /** @var Node $funcCallName */
                $funcCallName = $item->value->name;

                if ($funcCallName instanceof Name) {
                    $funcName = $funcCallName->getLast();

                    if ($this->scope->subjectReflection->hasMethod($funcName)) {
                        $spreadAnalysis = $this->analyzeThisMethodSpread($funcName);

                        if ($spreadAnalysis !== null) {
                            $analysis->merge($spreadAnalysis);
                        }
                    }
                }

                continue;
            }

            // Handle $this->merge([...]) or $this->mergeWhen(condition, [...])
            if ($item->key === null && $item->value instanceof MethodCall) {
                $mergeResult = $this->analyzeMergeExpression($item->value);

                $analysis->merge($mergeResult);

                continue;
            }

            if ($item->key === null) {
                continue;
            }

            $keyName = $this->resolveKeyName($item->key);

            if ($keyName === null) {
                continue;
            }

            $result = $this->analyzeValueExpression($item->value);

            // When a child key overrides a parent spread key, clear stale parent tracking
            unset(
                $analysis->enumResources[$keyName], $analysis->nestedResources[$keyName], $analysis->directEnumFqcns[$keyName],
                $analysis->modelFqcns[$keyName], $analysis->multiEnumResourceFqcns[$keyName], $analysis->inlineEnumFqcns[$keyName],
                $analysis->inlineModelFqcns[$keyName], $analysis->inlineEnumResourceFqcns[$keyName],
            );

            $analysis->properties[] = [
                'name' => $keyName,
                'type' => $result['type'],
                'optional' => $result['optional'],
                'description' => '',
            ];

            $this->dispatchFqcnResults(
                $keyName, $result, $analysis->enumResources, $analysis->directEnumFqcns,
                $analysis->nestedResources, $analysis->modelFqcns, $analysis->multiEnumResourceFqcns,
            );

            foreach ($result['embeddedEnumFqcns'] ?? [] as $fqcn) {
                $analysis->inlineEnumFqcns[$keyName][] = $fqcn;
            }

            foreach ($result['embeddedEnumResourceFqcns'] ?? [] as $fqcn) {
                $analysis->inlineEnumResourceFqcns[$keyName][] = $fqcn;
            }

            foreach ($result['embeddedModelFqcns'] ?? [] as $fqcn) {
                $analysis->inlineModelFqcns[$keyName][] = $fqcn;
            }

            foreach ($result['customImports'] ?? [] as $path => $types) {
                $analysis->customImports[$path] = [...($analysis->customImports[$path] ?? []), ...$types];
            }
        }

        return $analysis;
    }

    /**
     * Extracted expression handlers, tried before the legacy chain, in dispatch order.
     *
     * @return list<ExpressionHandler>
     */
    protected function handlers(): array
    {
        return [
            new FirstClassCallableHandler,
            new CastHandler,
            new ScalarHandler,
            new ConstFetchHandler,
            new ClassConstantHandler,
            new BinaryOpHandler,
            new CoalesceHandler,
            new KnownFunctionCallHandler,
            new ClosureHandler,
            new ConditionalMethodHandler,
            new ToResourceHandler,
            new StaticCallHandler,
            new NewResourceHandler,
        ];
    }

    /**
     * Lazily build the dispatcher once per instance — a per-call rebuild would defeat its memo.
     */
    protected function dispatcher(): ExpressionDispatcher
    {
        return $this->dispatcher ??= new ExpressionDispatcher($this->handlers());
    }

    /**
     * Analyze a value expression and return its type + optional status.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeValueExpression(Expr $expr): array
    {
        $dispatched = $this->dispatcher()->dispatch($expr, $this->scope, $this);

        if ($dispatched !== null) {
            return $dispatched;
        }

        $result = $this->unknownResult();

        if ($this->isThisPropertyFetch($expr)) {
            return $this->analyzeThisProperty($expr);
        }

        // $this->relation->only([...]) or $this->relation?->only([...])
        if (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall)
            && $expr->name instanceof Identifier
            && in_array($expr->name->toString(), $this->supportedAttributeFilters(), true)
            && $expr->var instanceof PropertyFetch
            && $expr->var->var instanceof Variable
            && $expr->var->var->name === 'this'
        ) {
            return $this->analyzeRelationFilter($expr);
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
            return $this->analyzeMapProxyFilter($expr);
        }

        if ($expr instanceof Array_) {
            return $this->analyzeInlineArray($expr);
        }

        if ($expr instanceof NullsafeMethodCall) {
            return $this->analyzeMethodChain($expr);
        }

        if ($expr instanceof NullsafePropertyFetch) {
            return $this->analyzePropertyChain($expr);
        }

        // $this->anyProp->subProp — e.g. $this->resource->name / ->value on a backed enum
        if ($expr instanceof PropertyFetch && $this->isThisPropertyFetch($expr->var)) {
            $info = $this->analyzeWrappedEnumResourceProperty($expr);

            if ($info['type'] === 'unknown') {
                $info = $this->analyzeWrappedModelResourceProperty($expr);
            }

            if ($info['type'] === 'unknown' && $this->scope->closureRelationModelClass !== null && $expr->name instanceof Identifier) {
                $info = $this->analyzeRelatedModelProperty($expr->name->toString(), $this->scope);
            }

            if ($info['type'] === 'unknown') {
                $info = $this->analyzePropertyChain($expr);
            }

            return $info;
        }

        // Plain 3+-deep chains rooted at `$this` (e.g. `$this->resource->user->role`): the 2-deep handler
        // above doesn't match, because `$expr->var` is not a direct `$this->prop`.
        if ($expr instanceof PropertyFetch) {
            $info = $this->analyzePropertyChain($expr);

            if ($info['type'] !== 'unknown') {
                return $info;
            }
        }

        // Collection chains rooted at `$this->{manyRelation}` (e.g. `->take(5)->map(...)->values()`).
        // Must precede the `$this->anyProp->method()` branch below: a 1-deep `$this->items->count()`
        // matches both, and this returns null for it so knownMethodRule()'s count()/exists() rule wins.
        if ($expr instanceof MethodCall) {
            $chainResult = $this->analyzeRelationCollectionChain($expr);

            if ($chainResult !== null) {
                return $chainResult;
            }
        }

        // $this->anyProp->method() — e.g. $this->resource->extensions() on a backed enum or model
        if ($expr instanceof MethodCall
            && $this->isThisPropertyFetch($expr->var)
            && $expr->name instanceof Identifier
        ) {
            $info = $this->analyzeWrappedResourceMethodCall($expr);

            /** @var class-string<Model>|null $closureModelClass */
            $closureModelClass = $this->scope->closureRelationModelClass;

            if ($info['type'] === 'unknown' && $closureModelClass !== null) {
                $info = $this->analyzeRelatedModelMethodCall($expr->name->toString(), $this->scope);
            }

            return $info;
        }

        // Generic `$this->method()` — reflect the declared return type; the helper guards above ran first.
        if ($expr instanceof MethodCall
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            return resolve(SubjectMethodTypeResolver::class)->resolve($this->scope, $expr->name->toString());
        }

        // $variable->property — resolve against the variable's own bound model (whenLoaded param,
        // chain map param, foreach value var), falling back to the ambient whenLoaded closure model.
        if ($expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && $expr->var->name !== 'this'
            && $expr->name instanceof Identifier
        ) {
            /** @var class-string<Model>|null $boundModel */
            $boundModel = $this->scope->varModelBindings[$expr->var->name] ?? $this->scope->closureRelationModelClass;

            if ($boundModel !== null) {
                return $this->analyzeRelatedModelProperty($expr->name->toString(), $this->scope, $boundModel);
            }
        }

        // `$variable->map(fn (TypedClass $item) => [...])` — no closureRelationModelClass is required
        // here, since the element type comes from the closure's own type hint.
        if ($expr instanceof MethodCall
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && $expr->var->name !== 'this'
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'map'
            && $expr->getArgs() !== []
        ) {
            $mapResult = $this->analyzeVariableMapCall($expr);

            if ($mapResult !== null) {
                return $mapResult;
            }
        }

        // $variable->pluck('field') — resolve to an array of the field's type
        if ($this->scope->closureRelationModelClass !== null
            && $expr instanceof MethodCall
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && $expr->var->name !== 'this'
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'pluck'
        ) {
            return $this->analyzeVariablePluckCall($expr);
        }

        // $variable->method() — resolve against the variable's own bound model, falling back to the
        // ambient whenLoaded closure model.
        if ($expr instanceof MethodCall
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && $expr->var->name !== 'this'
            && $expr->name instanceof Identifier
        ) {
            /** @var class-string<Model>|null $boundModel */
            $boundModel = $this->scope->varModelBindings[$expr->var->name] ?? $this->scope->closureRelationModelClass;

            if ($boundModel !== null) {
                return $this->analyzeRelatedModelMethodCall($expr->name->toString(), $this->scope, $boundModel);
            }
        }

        if ($expr instanceof Ternary) {
            return $this->analyzeTernary($expr);
        }

        // Bare variable bound to a model class (whenLoaded param, chain map param, foreach value var) —
        // resolves to the model's own type. Checked before closure-param/local-var expression bindings,
        // which resolve through a *different* expression rather than naming a model directly.
        if ($expr instanceof Variable && is_string($expr->name) && isset($this->scope->varModelBindings[$expr->name])) {
            $modelFqcn = $this->scope->varModelBindings[$expr->name];

            return [
                ...$this->unknownResult(),
                'type' => class_basename($modelFqcn),
                'optional' => false,
                'modelFqcn' => $modelFqcn,
            ];
        }

        // Bare variable bound to a whole relation collection (to-many whenLoaded param) — resolves to
        // the collection type, e.g. `User[]`, never the singular element model.
        if ($expr instanceof Variable && is_string($expr->name) && isset($this->scope->varCollectionBindings[$expr->name])) {
            $binding = $this->scope->varCollectionBindings[$expr->name];

            return [
                ...$this->unknownResult(),
                'type' => $binding['type'],
                'optional' => false,
                'modelFqcn' => $binding['modelFqcn'],
            ];
        }

        // Bare variable bound either to a closure parameter (ConditionalMethodHandler's
        // bindClosureParamsFromCondition()) or to a top-level local assignment
        // (collectLocalVarBindings). Closure-param bindings win, being the
        // narrower scope; the re-entrancy guard makes a cyclic binding resolve as unknown.
        if ($expr instanceof Variable && is_string($expr->name)) {
            $boundExpr = $this->scope->closureParamExprBindings[$expr->name]
                ?? $this->scope->localVarBindings[$expr->name]
                ?? null;

            if ($boundExpr !== null && ! isset($this->scope->resolvingLocalVars[$expr->name])) {
                $this->scope->resolvingLocalVars[$expr->name] = true;

                try {
                    return $this->analyzeValueExpression($boundExpr);
                } finally {
                    unset($this->scope->resolvingLocalVars[$expr->name]);
                }
            }
        }

        // Late known-method rules for receivers not matched above (e.g. `$request->user()->can(...)`,
        // whose receiver is itself a MethodCall). Only MethodCall reaches here — every
        // NullsafeMethodCall already returned via analyzeMethodChain().
        if ($expr instanceof MethodCall) {
            $known = $this->knownMethodRule($expr);

            if ($known !== null) {
                return $known;
            }
        }

        return $result;
    }

    /**
     * Analyze a ternary or Elvis expression, unioning both branches.
     *
     * In Elvis (`$cond ?: $else`) the parser leaves `if` null, so the truthy value is `$cond` itself.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeTernary(Ternary $expr): array
    {
        if ($expr->if === null) {
            return (new ClosureHandler)->analyzeClosureUnion([$expr->cond, $expr->else], $this);
        }

        return (new ClosureHandler)->analyzeClosureUnion([$expr->if, $expr->else], $this);
    }

    /**
     * Analyze $this->merge([...]), mergeWhen(condition, [...]), or mergeUnless(condition, [...]).
     *
     * merge() properties are required; mergeWhen()/mergeUnless() properties are optional.
     */
    protected function analyzeMergeExpression(MethodCall $call): ResourceAnalysis
    {
        $isMerge = $this->isThisMethodCall($call, 'merge');
        $isMergeWhen = $this->isThisMethodCall($call, 'mergeWhen');
        $isMergeUnless = $this->isThisMethodCall($call, 'mergeUnless');

        if (! $isMerge && ! $isMergeWhen && ! $isMergeUnless) {
            return new ResourceAnalysis; // @codeCoverageIgnore
        }

        if ($call->isFirstClassCallable()) {
            return new ResourceAnalysis; // @codeCoverageIgnore
        }

        $args = $call->getArgs();

        if ($isMerge && count($args) >= 1) {
            return $this->resolveArrayOrClosureToProperties($args[0]->value, optional: false);
        }

        if (($isMergeWhen || $isMergeUnless) && count($args) >= 2) {
            return $this->resolveArrayOrClosureToProperties($args[1]->value, optional: true);
        }

        return new ResourceAnalysis;
    }

    /**
     * Resolve an expression that's either an Array_ literal or a closure returning an Array_ into properties.
     * Handles multi-return closures (e.g. guard clause + data branch) by merging all branches.
     */
    protected function resolveArrayOrClosureToProperties(Expr $expr, bool $optional): ResourceAnalysis
    {
        if ($expr instanceof Array_) {
            return $this->extractPropertiesFromArray($expr, $optional);
        }

        $returnExprs = $this->resolveClosureReturnExpressions($expr);

        // Filter to non-empty Array_ expressions (skip guard clause `return []`)
        /** @var list<Array_> $arrays */
        $arrays = array_values(array_filter($returnExprs, fn (Expr $e) => $e instanceof Array_ && count($e->items) > 0));

        if ($arrays === []) {
            return new ResourceAnalysis;
        }

        if (count($arrays) === 1) {
            return $this->extractPropertiesFromArray($arrays[0], $optional);
        }

        $analyses = array_map(fn (Array_ $a) => $this->extractPropertiesFromArray($a, $optional), $arrays);

        return $this->mergeReturnBranches($analyses);
    }

    /**
     * Analyze $this->property — resolve the type from the backing model, attributes before relations
     * (matching Laravel's Model::__get).
     *
     * @return ValueExpressionResult
     */
    protected function analyzeThisProperty(Expr $expr): array
    {
        $result = $this->unknownResult();

        /** @var PropertyFetch $expr */
        $propName = $expr->name instanceof Identifier ? $expr->name->toString() : null;

        if ($propName === null) {
            return $result; // @codeCoverageIgnore
        }

        if ($propName === 'collection' && $this->isResourceCollection()) {
            return $this->analyzeCollectionProperty();
        }

        $info = $this->resolveModelAttributeTypeInfo($propName);

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

        $relationInfo = $this->resolveModelRelationTypeInfo($propName);

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
     * Extract properties and FQCNs from an array expression, e.g. for mergeWhen's second argument.
     */
    protected function extractPropertiesFromArray(Array_ $array, bool $optional = false): ResourceAnalysis
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

            $result = $this->analyzeValueExpression($item->value);

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
     * Resolve and analyze the parent resource's toArray() method.
     */
    protected function analyzeParentToArray(): ?ResourceAnalysis
    {
        $parentClass = $this->scope->subjectReflection->getParentClass();

        if ($parentClass === false || ! is_a($parentClass->getName(), JsonResource::class, true)) {
            return null; // @codeCoverageIgnore
        }

        if ($parentClass->getName() === JsonResource::class) {
            return $this->buildModelDelegatedAnalysis();
        }

        $parentAnalyzer = new self(
            $parentClass,
            $this->scope->modelClass,
        );

        return $parentAnalyzer->analyze();
    }

    /**
     * Resolve and analyze a $this->method() spread from a trait or the class itself.
     *
     * $localVarBindings/$resolvingLocalVars/$varModelBindings are saved, cleared, and restored via
     * `finally`, since a spread can recurse while the caller's own analysis is still mid-flight.
     */
    protected function analyzeThisMethodSpread(string $methodName): ?ResourceAnalysis
    {
        if (! $this->scope->subjectReflection->hasMethod($methodName)) {
            return null; // @codeCoverageIgnore
        }

        if (isset($this->scope->visitedSpreadMethods[$methodName])) {
            return null;
        }

        $this->scope->visitedSpreadMethods[$methodName] = true;

        $method = $this->scope->subjectReflection->getMethod($methodName);
        $context = resolve(MethodLocator::class)->locate($this->scope->subjectReflection->getName(), $methodName);
        $targetMethod = $context?->method;

        if ($targetMethod === null || $targetMethod->stmts === null) {
            return null; // @codeCoverageIgnore
        }

        $finder = new NodeFinder;

        $previousLocalVarBindings = $this->scope->localVarBindings;
        $previousResolvingLocalVars = $this->scope->resolvingLocalVars;
        $previousVarModelBindings = $this->scope->varModelBindings;
        $this->scope->localVarBindings = [];
        $this->scope->resolvingLocalVars = [];
        $this->scope->varModelBindings = [];
        $this->collectLocalVarBindings($targetMethod->stmts);

        try {
            $returnStmt = $finder->findFirst($targetMethod->stmts, function (Node $node): bool {
                return $node instanceof Return_;
            });

            if ($returnStmt instanceof Return_ && $returnStmt->expr instanceof Array_) {
                $analysis = $this->analyzeReturnArray($returnStmt->expr);
            } elseif ($returnStmt instanceof Return_ && $returnStmt->expr instanceof Variable
                && is_string($returnStmt->expr->name)) {
                $analysis = $this->resolveVariableReturnAnalysis($targetMethod->stmts, $returnStmt->expr->name);
            } elseif ($returnStmt instanceof Return_ && $returnStmt->expr instanceof MethodCall) {
                $filtered = $this->analyzeThisAttributeFilter($returnStmt->expr);

                if ($filtered !== null) {
                    $analysis = $filtered;
                } elseif ($this->hasThisReceiver($returnStmt->expr) && $returnStmt->expr->name instanceof Identifier) {
                    $analysis = $this->analyzeThisMethodSpread($returnStmt->expr->name->toString()) ?? new ResourceAnalysis;
                } else {
                    $analysis = new ResourceAnalysis;
                }
            } else {
                $analysis = new ResourceAnalysis;
            }
        } finally {
            $this->scope->localVarBindings = $previousLocalVarBindings;
            $this->scope->resolvingLocalVars = $previousResolvingLocalVars;
            $this->scope->varModelBindings = $previousVarModelBindings;
            unset($this->scope->visitedSpreadMethods[$methodName]);
        }

        $docTypes = $this->parseReturnArrayShape($method);

        if ($docTypes !== []) {
            $tsMap = LaravelTsPublish::typesMap();

            foreach ($analysis->properties as &$prop) {
                if ($prop['type'] !== 'unknown' || ! isset($docTypes[$prop['name']])) {
                    continue;
                }

                $prop['type'] = $this->resolvePhpDocType($docTypes[$prop['name']], $tsMap);
            }

            unset($prop);
        }

        $this->applyTsCastsFromMethod($method, $analysis);

        return $analysis;
    }

    /**
     * Decompose a property-fetch expression rooted at `$this` into ordered `{name, nullable}` steps,
     * where `nullable` marks a `?->` access. Returns null if the root is not `$this`.
     *
     * @return list<array{name: string, nullable: bool}>|null
     */
    private function decomposePropertyChain(Expr $expr): ?array
    {
        /** @var list<array{name: string, nullable: bool}> $chain */
        $chain = [];
        $current = $expr;

        while ($current instanceof PropertyFetch || $current instanceof NullsafePropertyFetch) {
            if (! $current->name instanceof Identifier) {
                return null;
            }

            $chain[] = [
                'name' => $current->name->toString(),
                'nullable' => $current instanceof NullsafePropertyFetch,
            ];

            $current = $current->var;
        }

        if (! $current instanceof Variable || $current->name !== 'this') {
            return null;
        }

        return array_reverse($chain);
    }

    /**
     * Analyze a property-fetch chain rooted at `$this`, traversing relation steps until the final
     * property resolves. Handles `->` and `?->` in any mix; any `?->` step appends `| null`.
     *
     * The starting model is $closureRelationModelClass inside a whenLoaded closure, else $modelClass.
     *
     * @return ValueExpressionResult
     */
    private function analyzePropertyChain(Expr $expr): array
    {
        $chain = $this->decomposePropertyChain($expr);

        if ($chain === null || $chain === []) {
            return $this->unknownResult();
        }

        /** @var class-string<Model>|null $currentModel */
        $currentModel = $this->scope->closureRelationModelClass ?? $this->scope->modelClass;

        if ($currentModel === null) {
            return $this->unknownResult();
        }

        $resolver = resolve(ModelAttributeResolver::class);

        // Skip the `$this->resource` wrapper property when it is not a real model relation
        if ($chain[0]['name'] === 'resource') {
            $check = $resolver->resolveRelation($currentModel, 'resource');

            if ($check['type'] === 'unknown') {
                array_shift($chain);
            }
        }

        if ($chain === []) {
            return $this->unknownResult();
        }

        $hasNullable = array_any($chain, fn (array $step): bool => $step['nullable']);

        $count = count($chain);

        // Inside a whenLoaded closure the first step may be the resource's proxy to the already-loaded
        // relation model (`$this->user` in `whenLoaded('user', fn() => $this->user?->name)`) — skip it.
        $startIndex = 0;

        if ($this->scope->closureRelationModelClass !== null && $count >= 2) {
            $firstRelation = $resolver->resolveRelation($currentModel, $chain[0]['name']);

            if ($firstRelation['type'] === 'unknown') {
                $startIndex = 1;
            }
        }

        for ($i = $startIndex; $i < $count - 1; $i++) {
            $relationInfo = $resolver->resolveRelation($currentModel, $chain[$i]['name']);

            if ($relationInfo['type'] === 'unknown' || $relationInfo['modelFqcn'] === null) {
                return $this->unknownResult();
            }

            $currentModel = $relationInfo['modelFqcn'];
        }

        $lastStep = $chain[$count - 1];
        $tsInfo = $resolver->resolveAttribute($currentModel, $lastStep['name']);

        if ($tsInfo['type'] === 'unknown') {
            // The final step may itself be a relation (e.g. $this->user?->profile).
            $relationInfo = $resolver->resolveRelation($currentModel, $lastStep['name']);

            if ($relationInfo['type'] === 'unknown') {
                return $this->unknownResult();
            }

            $type = $hasNullable && ! str_ends_with($relationInfo['type'], ' | null')
                ? $relationInfo['type'].' | null'
                : $relationInfo['type'];

            /** @var ValueExpressionResult $result */
            $result = ['type' => $type, 'optional' => false];

            if ($relationInfo['modelFqcn'] !== null) {
                $result['modelFqcn'] = $relationInfo['modelFqcn'];
            }

            if ($relationInfo['morphFqcns'] !== []) {
                $result['embeddedModelFqcns'] = $relationInfo['morphFqcns'];
            }

            return $result;
        }

        $type = $hasNullable && ! str_ends_with($tsInfo['type'], ' | null')
            ? $tsInfo['type'].' | null'
            : $tsInfo['type'];

        /** @var ValueExpressionResult $result */
        $result = ['type' => $type, 'optional' => false];

        /** @var class-string|null $enumFqcn */
        $enumFqcn = $tsInfo['enumFqcns'][0] ?? null;

        if ($enumFqcn !== null) {
            $result['directEnumFqcn'] = $enumFqcn;
        }

        return $result;
    }

    /**
     * Analyze a nullsafe method-call chain rooted at `$this`, traversing relations to the terminal
     * model and resolving the method's return type on it. The `?->` operator makes the result nullable.
     *
     * @return ValueExpressionResult
     */
    private function analyzeMethodChain(NullsafeMethodCall $call): array
    {
        $methodName = $call->name instanceof Identifier ? $call->name->toString() : null;

        if ($methodName === null) {
            return $this->unknownResult();
        }

        $chain = $this->decomposePropertyChain($call->var);

        if ($chain === null || $chain === []) {
            return $this->unknownResult();
        }

        /** @var class-string<Model>|null $currentModel */
        $currentModel = $this->scope->closureRelationModelClass ?? $this->scope->modelClass;

        if ($currentModel === null) {
            return $this->unknownResult();
        }

        $resolver = resolve(ModelAttributeResolver::class);

        // Skip the `$this->resource` wrapper property when it is not a real model relation
        if ($chain[0]['name'] === 'resource') {
            $check = $resolver->resolveRelation($currentModel, 'resource');

            if ($check['type'] === 'unknown') {
                array_shift($chain);
            }
        }

        if ($chain === []) {
            return $this->unknownResult();
        }

        $count = count($chain);

        // Inside a whenLoaded closure the first step may be the resource's proxy to the already-loaded
        // relation model (`$this->categoryRel` in `whenLoaded('categoryRel', ...)`) — skip it.
        $startIndex = 0;

        if ($this->scope->closureRelationModelClass !== null) {
            $firstRelation = $resolver->resolveRelation($currentModel, $chain[0]['name']);

            if ($firstRelation['type'] === 'unknown') {
                $startIndex = 1;
            }
        }

        for ($i = $startIndex; $i < $count - 1; $i++) {
            $relationInfo = $resolver->resolveRelation($currentModel, $chain[$i]['name']);

            if ($relationInfo['type'] === 'unknown' || $relationInfo['modelFqcn'] === null) {
                return $this->unknownResult();
            }

            /** @var class-string<Model> $relatedModel */
            $relatedModel = $relationInfo['modelFqcn'];
            $currentModel = $relatedModel;
        }

        if ($startIndex <= $count - 1) {
            $lastStep = $chain[$count - 1];
            $relationInfo = $resolver->resolveRelation($currentModel, $lastStep['name']);

            if ($relationInfo['type'] !== 'unknown' && $relationInfo['modelFqcn'] !== null) {
                /** @var class-string<Model> $relatedModel */
                $relatedModel = $relationInfo['modelFqcn'];
                $currentModel = $relatedModel;
            }
        }

        $tsInfo = $resolver->resolveMethodReturnType($currentModel, $methodName);

        if ($tsInfo['type'] === '' || $tsInfo['type'] === 'unknown') {
            // Same convention rules analyzeWrappedResourceMethodCall() uses for the non-nullsafe chain.
            $tsInfo = $this->knownMethodRule($call) ?? $this->unknownResult();
        }

        if ($tsInfo['type'] === 'unknown') {
            return $this->unknownResult();
        }

        $type = str_ends_with($tsInfo['type'], ' | null')
            ? $tsInfo['type']
            : $tsInfo['type'].' | null';

        return ['type' => $type, 'optional' => false];
    }

    /**
     * Apply #[TsCasts] overrides declared on a reflection method, updating or injecting properties.
     *
     * Accepted on trait/helper methods and on toArray() itself, as a lightweight override mechanism.
     */
    private function applyTsCastsFromMethod(ReflectionMethod $method, ResourceAnalysis $analysis): void
    {
        foreach ($method->getAttributes(TsCasts::class) as $attr) {
            $instance = $attr->newInstance();

            foreach ($instance->types as $property => $value) {
                $type = is_array($value) ? $value['type'] : $value;
                $optional = is_array($value) && isset($value['optional']) ? (bool) $value['optional'] : null;

                $found = false;

                foreach ($analysis->properties as &$prop) {
                    if ($prop['name'] === $property) {
                        $prop['type'] = $type;

                        if ($optional !== null) {
                            $prop['optional'] = $optional;
                        }

                        $found = true;

                        break;
                    }
                }

                unset($prop);

                if (! $found) {
                    $analysis->properties[] = [
                        'name' => $property,
                        'type' => $type,
                        'optional' => $optional ?? false,
                        'description' => '',
                    ];
                }

                if (is_array($value) && isset($value['import'])) {
                    foreach (LaravelTsPublish::extractImportableTypes($type) as $importName) {
                        $analysis->customImports[$value['import']][] = $importName;
                    }
                }
            }
        }
    }

    /**
     * Resolve properties from a method that builds an array variable and returns it.
     *
     * Handles: $data = [...]; $data['key'] = expr; if (...) { $data['key'] = expr; } return $data;
     *
     * @param  array<Node\Stmt>  $stmts
     */
    protected function resolveVariableReturnAnalysis(array $stmts, string $varName): ResourceAnalysis
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

        $this->collectVariableArrayAssignments(
            $stmts, $varName, false,
            $properties, $enumResources, $nestedResources,
            $directEnumFqcns, $modelFqcns, $customImports, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
            $inlineEnumResourceFqcns,
        );

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
     * Recursively collect array assignments to a variable from method statements.
     *
     * Assignments inside if/elseif/else blocks are marked as optional.
     *
     * @param  array<Node\Stmt>  $stmts
     * @param  ResourcePropertyInfoList  $properties
     * @param  ClassMapType  $enumResources
     * @param  ClassMapType  $nestedResources
     * @param  ClassMapType  $directEnumFqcns
     * @param  ClassMapType  $modelFqcns
     * @param  ImportMapType  $customImports
     * @param  InlineEnumFqcnsMap  $inlineEnumFqcns
     * @param  InlineModelFqcnsMap  $inlineModelFqcns
     * @param  MultiEnumFqcnsMap  $multiEnumResourceFqcns
     * @param  InlineEnumFqcnsMap  $inlineEnumResourceFqcns
     */
    protected function collectVariableArrayAssignments(
        array $stmts,
        string $varName,
        bool $isConditional,
        array &$properties,
        array &$enumResources,
        array &$nestedResources,
        array &$directEnumFqcns,
        array &$modelFqcns,
        array &$customImports,
        array &$inlineEnumFqcns,
        array &$inlineModelFqcns,
        array &$multiEnumResourceFqcns = [],
        array &$inlineEnumResourceFqcns = [],
    ): void {
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof ExpressionStmt && ! $stmt instanceof If_
                && ! $stmt instanceof Foreach_ && ! $stmt instanceof For_
                && ! $stmt instanceof While_ && ! $stmt instanceof Do_) {
                continue;
            }

            // $var = [...] — base array assignment
            if ($stmt instanceof ExpressionStmt
                && $stmt->expr instanceof Assign
                && $stmt->expr->var instanceof Variable
                && $stmt->expr->var->name === $varName
                && $stmt->expr->expr instanceof Array_) {
                $baseAnalysis = $this->analyzeReturnArray($stmt->expr->expr);

                if ($isConditional) {
                    foreach ($baseAnalysis->properties as &$prop) {
                        $prop['optional'] = true;
                    }

                    unset($prop);
                }

                $accumulator = new ResourceAnalysis(
                    $properties, $enumResources, $nestedResources,
                    customImports: $customImports,
                    directEnumFqcns: $directEnumFqcns,
                    modelFqcns: $modelFqcns,
                    inlineEnumFqcns: $inlineEnumFqcns,
                    inlineModelFqcns: $inlineModelFqcns,
                    multiEnumResourceFqcns: $multiEnumResourceFqcns,
                    inlineEnumResourceFqcns: $inlineEnumResourceFqcns,
                );
                $accumulator->merge($baseAnalysis);

                $properties = $accumulator->properties;
                $enumResources = $accumulator->enumResources;
                $nestedResources = $accumulator->nestedResources;
                $directEnumFqcns = $accumulator->directEnumFqcns;
                $modelFqcns = $accumulator->modelFqcns;
                $customImports = $accumulator->customImports;
                $inlineEnumFqcns = $accumulator->inlineEnumFqcns;
                $inlineModelFqcns = $accumulator->inlineModelFqcns;
                $multiEnumResourceFqcns = $accumulator->multiEnumResourceFqcns;
                $inlineEnumResourceFqcns = $accumulator->inlineEnumResourceFqcns;

                continue;
            }

            // $var['key'] = expr — individual key assignment
            if ($stmt instanceof ExpressionStmt
                && $stmt->expr instanceof Assign
                && $stmt->expr->var instanceof ArrayDimFetch
                && $stmt->expr->var->var instanceof Variable
                && $stmt->expr->var->var->name === $varName
                && $stmt->expr->var->dim instanceof String_) {
                $keyName = $stmt->expr->var->dim->value;
                $result = $this->analyzeValueExpression($stmt->expr->expr);
                $optional = $isConditional || $result['optional'];

                $existingIndex = null;

                foreach ($properties as $index => $existing) {
                    if ($existing['name'] === $keyName) {
                        $existingIndex = $index;

                        break;
                    }
                }

                if ($existingIndex !== null) {
                    $properties[$existingIndex] = [
                        'name' => $keyName,
                        'type' => $result['type'],
                        'optional' => $properties[$existingIndex]['optional'] && $optional,
                        'description' => '',
                    ];
                } else {
                    $properties[] = [
                        'name' => $keyName,
                        'type' => $result['type'],
                        'optional' => $optional,
                        'description' => '',
                    ];
                }

                unset(
                    $enumResources[$keyName],
                    $nestedResources[$keyName],
                    $directEnumFqcns[$keyName],
                    $modelFqcns[$keyName],
                    $multiEnumResourceFqcns[$keyName],
                );

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

                continue;
            }

            if ($stmt instanceof If_) {
                $this->collectVariableArrayAssignments(
                    $stmt->stmts, $varName, true,
                    $properties, $enumResources, $nestedResources,
                    $directEnumFqcns, $modelFqcns, $customImports, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
                    $inlineEnumResourceFqcns,
                );

                foreach ($stmt->elseifs as $elseif) {
                    $this->collectVariableArrayAssignments(
                        $elseif->stmts, $varName, true,
                        $properties, $enumResources, $nestedResources,
                        $directEnumFqcns, $modelFqcns, $customImports, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
                        $inlineEnumResourceFqcns,
                    );
                }

                if ($stmt->else !== null) {
                    $this->collectVariableArrayAssignments(
                        $stmt->else->stmts, $varName, true,
                        $properties, $enumResources, $nestedResources,
                        $directEnumFqcns, $modelFqcns, $customImports, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
                        $inlineEnumResourceFqcns,
                    );
                }
            }

            // Loop bodies are conditional: a loop may execute zero times.
            if ($stmt instanceof Foreach_ || $stmt instanceof For_
                || $stmt instanceof While_ || $stmt instanceof Do_) {
                $this->collectVariableArrayAssignments(
                    $stmt->stmts, $varName, true,
                    $properties, $enumResources, $nestedResources,
                    $directEnumFqcns, $modelFqcns, $customImports, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
                    $inlineEnumResourceFqcns,
                );
            }
        }
    }

    /**
     * Parse a @return array shape PHPDoc annotation into a property-name → PHP-type map.
     *
     * Supports: @return array{key: type, key2: type2, ...}
     *
     * @return array<string, string>
     */
    protected function parseReturnArrayShape(ReflectionMethod $method): array
    {
        $docComment = $method->getDocComment();

        if ($docComment === false) {
            return [];
        }

        if (! preg_match('/@return\s+array\{([^}]+)\}/', $docComment, $matches)) {
            return [];
        }

        $result = [];
        $entries = explode(',', $matches[1]);

        foreach ($entries as $entry) {
            $entry = trim($entry);
            $entry = (string) preg_replace('/^\*\s*/', '', $entry);

            if (! str_contains($entry, ':')) {
                continue;
            }

            [$key, $type] = explode(':', $entry, 2);
            $result[trim($key)] = trim($type);
        }

        return $result;
    }

    /**
     * Convert a PHPDoc type string (e.g. "string|null") to its TypeScript equivalent.
     *
     * @param  array<string, string|(callable(): string)>  $tsMap
     */
    protected function resolvePhpDocType(string $phpType, array $tsMap): string
    {
        $parts = array_map('trim', explode('|', $phpType));
        $resolved = [];

        foreach ($parts as $part) {
            $lower = strtolower($part);
            $mapped = $tsMap[$lower] ?? null;

            if (is_string($mapped)) {
                $resolved[] = $mapped;
            } elseif (is_callable($mapped)) {
                $resolved[] = (string) $mapped();
            } else {
                $resolved[] = $part;
            }
        }

        return implode(' | ', array_unique($resolved));
    }

    /**
     * Determine whether the analyzed resource is a ResourceCollection subclass.
     */
    protected function isResourceCollection(): bool
    {
        return $this->scope->subjectReflection->isSubclassOf(ResourceCollection::class);
    }

    /**
     * Resolve the singular resource FQCN this ResourceCollection collects.
     * See InspectsAstNodes::resolveCollectedResourceClass() for the resolution order.
     *
     * @return class-string<JsonResource>|null
     */
    protected function resolveSingularResourceClass(): ?string
    {
        /** @var class-string $ownFqcn */
        $ownFqcn = $this->scope->subjectReflection->getName();

        return $this->resolveCollectedResourceClass($ownFqcn);
    }

    /**
     * Build a ResourceAnalysis for a ResourceCollection subclass that has no toArray() method.
     *
     * A non-empty $wrap key produces `{ data: R[] }`, keyed as `Record<string, R>` when the collection
     * preserves keys; a null $wrap makes that same element type the flatTypeAlias directly.
     */
    protected function buildCollectionDelegatedAnalysis(): ResourceAnalysis
    {
        $singular = $this->resolveSingularResourceClass();

        if ($singular === null) {
            return new ResourceAnalysis;
        }

        // Read $wrap declared on this class only — inherited, JsonResource's static default is 'data'.
        $wrapKey = 'data';

        if ($this->scope->subjectReflection->hasProperty('wrap')) {
            $wrapProp = $this->scope->subjectReflection->getProperty('wrap');

            if ($wrapProp->getDeclaringClass()->getName() === $this->scope->subjectReflection->getName()) {
                /** @var string|null $wrapKey */
                $wrapKey = $wrapProp->getDefaultValue();
            }
        }

        $elementType = $this->wrapCollectionElementType(LaravelTsPublish::resourceTypeName($singular), $this->scope->subjectReflection);

        if ($wrapKey === null || $wrapKey === '') {
            return new ResourceAnalysis(flatTypeAlias: $elementType, flatTypeAliasFqcn: $singular);
        }

        $key = $wrapKey ? $wrapKey : 'data';

        return new ResourceAnalysis(
            properties: [[
                'name' => $key,
                'type' => $elementType,
                'optional' => false,
                'description' => '',
            ]],
            nestedResources: [$wrapKey => $singular],
        );
    }

    /**
     * Analyze $this->collection in a ResourceCollection, resolving it to the singular resource
     * type as an array, or a keyed record when the collection preserves keys.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeCollectionProperty(): array
    {
        $result = $this->unknownResult();
        $singular = $this->resolveSingularResourceClass();

        if ($singular === null) {
            return $result;
        }

        return [
            ...$result,
            'type' => $this->wrapCollectionElementType(LaravelTsPublish::resourceTypeName($singular), $this->scope->subjectReflection),
            'resourceFqcn' => $singular,
        ];
    }

    /**
     * Analyze `$this->relation->only([...])` or `$this->relation?->only([...])`.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeRelationFilter(MethodCall|NullsafeMethodCall $call): array
    {
        $result = $this->unknownResult();

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

        $relationInfo = $this->resolveModelRelationTypeInfo($propName);
        $modelFqcn = $relationInfo['modelFqcn'] ?? $this->resolveAccessorModelFqcn($propName);

        if ($modelFqcn === null) {
            // Try the multi-model accessor path (e.g. Attribute<ModelA|ModelB, never>).
            $modelFqcns = $this->resolveAccessorModelFqcns($propName);

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
            /** @var ImportMapType $embeddedCustomImports */
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
    protected function analyzeMapProxyFilter(MethodCall|NullsafeMethodCall $call): array
    {
        $result = $this->unknownResult();

        $methodName = $call->name instanceof Identifier ? $call->name->toString() : null;

        if ($methodName === null) {
            return $result; // @codeCoverageIgnore
        }

        /** @var PropertyFetch $mapFetch */
        $mapFetch = $call->var;
        $elementModel = $this->resolveMapProxyElementModel($mapFetch->var);

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
     * @return class-string<Model>|null
     */
    protected function resolveMapProxyElementModel(Expr $receiver): ?string
    {
        if ($receiver instanceof Variable
            && is_string($receiver->name)
            && isset($this->scope->varCollectionBindings[$receiver->name])
        ) {
            return $this->scope->varCollectionBindings[$receiver->name]['modelFqcn'];
        }

        if ($receiver instanceof PropertyFetch
            && $this->isThisPropertyFetch($receiver)
            && $receiver->name instanceof Identifier
        ) {
            $relationInfo = $this->resolveModelRelationTypeInfo($receiver->name->toString());

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
    protected function relationFilterModelReference(string $modelFqcn, array $keys, bool $include): ?string
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

    /**
     * Analyze an inline array literal and produce an inline TypeScript object type.
     *
     * e.g. `['name' => $this->resource->name, 'value' => $this->maxSizeMb()]`
     * becomes `{ name: string; value: number }`.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeInlineArray(Array_ $array): array
    {
        $analysis = $this->analyzeReturnArray($array);

        // `json_encode([])` emits `[]`, not `{}` — only an array whose keys we failed to resolve is
        // honestly a record. `never[]` says the literal can hold nothing, which is what `[]` means.
        if ($array->items === []) {
            return ['type' => 'never[]', 'optional' => false];
        }

        // A spread whose value resolves to a bare named resource (not an array/collection of one), to a
        // bound model's toArray(), or to a bound collection's toArray(), intersects with the literal's keys.
        $spreadArms = $this->collectInlineArraySpreadArms($array);

        if ($analysis->properties === [] && $spreadArms === []) {
            return ['type' => 'Record<string, unknown>', 'optional' => false];
        }

        $useTolki = Config::boolean('ts-publish.enums.use_tolki_package');

        // Tolki on: EnumResource-wrapped properties render as `AsEnum<typeof X>`, matching the
        // top-level enum resource transformer. Substituting the bare token in place keeps every
        // other union arm — a keyed `Record<...>` arm, an extra default arm — intact.
        if ($useTolki) {
            foreach ($analysis->properties as &$prop) {
                if (! isset($analysis->enumResources[$prop['name']])) {
                    continue;
                }

                $fqcn = $analysis->enumResources[$prop['name']];
                $tsInfo = LaravelTsPublish::toTsType($fqcn);
                $constName = $tsInfo['enums'][0] ?? class_basename($fqcn);
                $bareTypeName = $tsInfo['enumTypes'][0] ?? class_basename($fqcn).'Type';
                $asEnumType = 'AsEnum<typeof '.$constName.'>';

                // A mixed wrap/direct ternary needs both arms named, whether or not the merged union
                // still shows them apart; blanket substitution would rewrite the direct arm too.
                $isMixed = ($analysis->directEnumFqcns[$prop['name']] ?? null) === $fqcn;
                $members = LaravelTsPublish::splitTopLevelUnion($prop['type']);

                $prop['type'] = $isMixed
                    ? $this->expandMixedEnumType($members, $bareTypeName, $asEnumType)
                    : $this->substituteEnumType($prop['type'], $bareTypeName, $asEnumType);
            }

            unset($prop);
        }

        // Each spread resource intersects with the remaining explicit keys, minus whichever of its
        // own keys a later spread arm or an explicit key also sets — PHP's `[...a, ...b, 'k' => v]`
        // lets the later assignment win, `&` does not, so the earlier arm needs Omit<>'d.
        $spreadArmTypes = array_values(array_unique(
            $this->buildSpreadArmTypes($spreadArms, array_column($analysis->properties, 'name')),
        ));
        $type = match (true) {
            $spreadArms === [] => $this->buildInlineObjectType($analysis),
            $analysis->properties === [] => implode(' & ', $spreadArmTypes),
            default => implode(' & ', [...$spreadArmTypes, $this->buildInlineObjectType($analysis)]),
        };

        $result = ['type' => $type, 'optional' => false];

        // Propagate import metadata so FQCNs referenced inside the inline object reach the outer analysis.
        // With Tolki enabled, enum resources need value imports (const) rather than type imports; direct
        // enum accesses always need type imports.
        if ($useTolki) {
            $nestedInlineEnumFqcns = $analysis->inlineEnumFqcns === []
                 ? []
                 : array_merge(...array_values($analysis->inlineEnumFqcns));

            $embeddedEnumFqcns = array_values(array_unique([
                ...array_values($analysis->directEnumFqcns),
                // Propagate any deeply-nested direct enum FQCNs from sub-inline-arrays.
                ...$nestedInlineEnumFqcns,
            ]));

            $enumResourceFqcns = array_values($analysis->enumResources);
            // Propagate any deeply-nested enum resource FQCNs from sub-inline-arrays.
            foreach ($analysis->inlineEnumResourceFqcns as $nestedFqcns) {
                foreach ($nestedFqcns as $fqcn) {
                    $enumResourceFqcns[] = $fqcn;
                }
            }
            $embeddedEnumResourceFqcns = array_values(array_unique($enumResourceFqcns));
        } else {
            // Tolki OFF: all enum FQCNs (both direct and EnumResource) need type imports.
            $embeddedEnumFqcns = array_values(array_unique([
                ...array_values($analysis->directEnumFqcns),
                ...array_values($analysis->enumResources),
                ...array_merge(...array_values($analysis->inlineEnumFqcns)),
                ...array_merge(...array_values($analysis->inlineEnumResourceFqcns)),
            ]));
            $embeddedEnumResourceFqcns = [];
        }

        // Each spread arm's import travels the channel matching its kind, or the emitted `Model &`
        // token would be looked up among the resources and never resolve to an import.
        $spreadModelFqcns = array_column(array_filter($spreadArms, fn (array $arm): bool => $arm['isModel']), 'fqcn');
        $spreadResourceFqcns = array_column(array_filter($spreadArms, fn (array $arm): bool => ! $arm['isModel']), 'fqcn');

        // Walk members in declaration order and keep every occurrence: the self-keyed $analysis->modelFqcns
        // map collapses repeated FQCNs onto one key, dropping a multi-FQCN accessor member's own arms.
        /** @var list<class-string> $embeddedModelFqcns */
        $embeddedModelFqcns = [];

        foreach ($analysis->properties as $property) {
            $memberName = $property['name'];

            if (isset($analysis->inlineModelFqcns[$memberName])) {
                array_push($embeddedModelFqcns, ...$analysis->inlineModelFqcns[$memberName]);
            } elseif (isset($analysis->modelFqcns[$memberName])) {
                $embeddedModelFqcns[] = $analysis->modelFqcns[$memberName];
            }
        }

        array_push($embeddedModelFqcns, ...$spreadModelFqcns);

        if ($embeddedEnumFqcns !== []) {
            $result['embeddedEnumFqcns'] = $embeddedEnumFqcns;
        }

        if ($embeddedEnumResourceFqcns !== []) {
            $result['embeddedEnumResourceFqcns'] = $embeddedEnumResourceFqcns;
        }

        if ($embeddedModelFqcns !== []) {
            $result['embeddedModelFqcns'] = $embeddedModelFqcns;
        }

        // Nested resources are tracked separately so they merge into resource imports, not model imports.
        // Spread resources travel the same channel so their import reaches the outer analysis too.
        if ($analysis->nestedResources !== [] || $spreadResourceFqcns !== []) {
            $result['embeddedResourceFqcns'] = array_values(array_unique([
                ...array_values($analysis->nestedResources),
                ...$spreadResourceFqcns,
            ]));
        }

        // A #[TsType(import: …)] token inside the inline object is spelled in the emitted type string,
        // so its import has to travel out with it.
        if ($analysis->customImports !== []) {
            $result['customImports'] = $analysis->customImports;
        }

        return $result;
    }

    /**
     * Flatten an analysis's properties into an inline TypeScript object literal type.
     *
     * Any enum-token substitution has to be applied to the properties before this is called.
     */
    private function buildInlineObjectType(ResourceAnalysis $analysis): string
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

    /**
     * Collect every spread in an inline array resolving to a bare named resource, to a bound
     * model's toArray(), or to a bound collection's toArray(), in source order — the arms an
     * intersection type is built from.
     *
     * @return list<InlineSpreadArm>
     */
    private function collectInlineArraySpreadArms(Array_ $array): array
    {
        /** @var list<InlineSpreadArm> $spreadArms */
        $spreadArms = [];

        foreach ($array->items as $item) {
            if ($item->key !== null || ! $item->unpack || $this->isKnownArraySpreadShape($item->value)) {
                continue;
            }

            $modelFqcn = $this->spreadModelToArrayFqcn($item->value);

            if ($modelFqcn !== null) {
                $spreadArms[] = ['fqcn' => $modelFqcn, 'isModel' => true, 'isCollection' => false];

                continue;
            }

            $collectionFqcn = $this->spreadCollectionToArrayFqcn($item->value);

            if ($collectionFqcn !== null) {
                $spreadArms[] = ['fqcn' => $collectionFqcn, 'isModel' => true, 'isCollection' => true];

                continue;
            }

            $spreadResult = $this->analyzeValueExpression($item->value);

            if (isset($spreadResult['resourceFqcn']) && $spreadResult['type'] === LaravelTsPublish::resourceTypeName($spreadResult['resourceFqcn'])) {
                $spreadArms[] = ['fqcn' => $spreadResult['resourceFqcn'], 'isModel' => false, 'isCollection' => false];
            }
        }

        return $spreadArms;
    }

    /**
     * Resolve `$var->toArray()` to the name of `$var`, or null when the expression is not that shape.
     *
     * `$this->toArray()` is the resource's own method and is handled elsewhere, so it is excluded
     * by name — `$this` parses as a `Variable` too, which would otherwise match incidentally.
     */
    private function spreadToArrayVarName(Expr $expr): ?string
    {
        if (! $expr instanceof MethodCall
            || ! $expr->name instanceof Identifier
            || $expr->name->toString() !== 'toArray'
            || ! $expr->var instanceof Variable
            || ! is_string($expr->var->name)
            || $expr->var->name === 'this') {
            return null;
        }

        return $expr->var->name;
    }

    /**
     * Resolve `$var->toArray()`, where `$var` is a closure-bound model, to that model's FQCN.
     *
     * @return class-string<Model>|null
     */
    private function spreadModelToArrayFqcn(Expr $expr): ?string
    {
        $varName = $this->spreadToArrayVarName($expr);

        if ($varName === null) {
            return null;
        }

        if (isset($this->scope->varModelBindings[$varName])) {
            return $this->scope->varModelBindings[$varName];
        }

        // A to-many whenLoaded param holds the whole collection, not one element — its toArray()
        // is a list of member arrays, never a single model's shape. spreadCollectionToArrayFqcn()
        // picks it up instead.
        if (isset($this->scope->varCollectionBindings[$varName])) {
            return null;
        }

        return $this->scope->closureRelationModelClass;
    }

    /**
     * Resolve `$var->toArray()`, where `$var` is a closure-bound relation collection, to its
     * element model's FQCN.
     *
     * @return class-string<Model>|null
     */
    private function spreadCollectionToArrayFqcn(Expr $expr): ?string
    {
        $varName = $this->spreadToArrayVarName($expr);

        return $varName === null ? null : ($this->scope->varCollectionBindings[$varName]['modelFqcn'] ?? null);
    }

    /**
     * Build each spread's intersection arm, `Omit<>`'d against every key a later arm or an
     * explicit key will overwrite at runtime. `Omit<T, K>` doesn't require `K extends keyof T`,
     * so a later arm's own shape never has to be resolved — only its name, for `keyof`.
     *
     * @param  list<InlineSpreadArm>  $spreadArms
     * @param  list<string>  $explicitKeyNames
     * @return list<string>
     */
    private function buildSpreadArmTypes(array $spreadArms, array $explicitKeyNames): array
    {
        $explicitKeyLiterals = array_map(fn (string $key): string => "'{$key}'", $explicitKeyNames);

        return array_map(function (int $index) use ($spreadArms, $explicitKeyLiterals): string {
            $armName = LaravelTsPublish::resourceTypeName($spreadArms[$index]['fqcn']);

            // Spreading a collection renumbers its elements 0..n, so a collection arm holds only
            // numeric keys: nothing string-keyed can overwrite it, and it overwrites nothing.
            if ($spreadArms[$index]['isCollection']) {
                return "Record<number, {$armName}>";
            }

            $laterArmNames = array_values(array_unique(array_map(
                fn (array $arm): string => LaravelTsPublish::resourceTypeName($arm['fqcn']),
                array_filter(array_slice($spreadArms, $index + 1), fn (array $arm): bool => ! $arm['isCollection']),
            )));

            $excluded = [
                ...$explicitKeyLiterals,
                ...array_map(fn (string $name): string => "keyof {$name}", $laterArmNames),
            ];

            return $excluded === [] ? $armName : 'Omit<'.$armName.', '.implode(' | ', $excluded).'>';
        }, array_keys($spreadArms));
    }

    /**
     * Whether a spread's value matches one of the four shapes analyzeReturnArray()'s item loop
     * already flattens into named properties (parent::toArray(), ->only()/->except(), a bare
     * `$this->method()`, or a bare function call) — already handled, so not a resource candidate.
     */
    private function isKnownArraySpreadShape(Expr $value): bool
    {
        if ($this->isParentToArrayCall($value)) {
            return true;
        }

        if ($value instanceof MethodCall && $value->var instanceof Variable && $value->var->name === 'this') {
            return true;
        }

        return $value instanceof FuncCall;
    }

    /**
     * Analyze `$this->anyProp->subProp` — a property fetch on one of `$this`'s properties.
     *
     * PHP enum universals: `->name` is always string, `->value` follows the enum's backing type.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWrappedEnumResourceProperty(PropertyFetch $expr): array
    {
        $result = $this->unknownResult();
        $innerProp = $expr->name instanceof Identifier ? $expr->name->toString() : null;

        if ($innerProp === null) {
            return $result; // @codeCoverageIgnore
        }

        // Guarded on the wrapped type actually being an enum: without it, a model-backed
        // `$this->resource->column` would silently receive 'string' instead of its column type.
        $wrappedClass = $this->resolveWrappedClass();

        if ($wrappedClass === null || ! enum_exists($wrappedClass)) {
            return $result;
        }

        if ($innerProp === 'name') {
            return [
                ...$result,
                'type' => 'string',
            ];
        }

        if ($innerProp === 'value') {
            return [
                ...$result,
                'type' => $this->resolveEnumValueBackingType(),
            ];
        }

        return $result;
    }

    /**
     * Analyze `$this->anyProp->subProp` where `$this->anyProp` is a wrapped model resource
     * (i.e. has a `@var ModelType|null` docblock on `$resource`).
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWrappedModelResourceProperty(PropertyFetch $expr): array
    {
        $result = $this->unknownResult();
        $innerProp = $expr->name instanceof Identifier ? $expr->name->toString() : null;

        if ($innerProp === null) {
            return $result; // @codeCoverageIgnore
        }

        $wrappedClass = $this->resolveWrappedClass();

        if ($wrappedClass === null || ! class_exists($wrappedClass)) {
            return $result;
        }

        $info = $this->resolveModelAttributeTypeInfo($innerProp);

        if ($info['type'] !== 'unknown') {
            $result = ['type' => $info['type'], 'optional' => false];

            if ($info['enumFqcn'] !== null) {
                $result['directEnumFqcn'] = $info['enumFqcn']; // @codeCoverageIgnore
            }

            return $result;
        }

        return $result; // @codeCoverageIgnore
    }

    /**
     * Analyze `$this->anyProp->method()` by resolving the method on the wrapped class.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWrappedResourceMethodCall(MethodCall $expr): array
    {
        $result = $this->unknownResult();
        $methodName = $expr->name instanceof Identifier ? $expr->name->toString() : null;

        if ($methodName === null) {
            return $result; // @codeCoverageIgnore
        }

        $wrappedClass = $this->resolveWrappedClass();

        if ($wrappedClass !== null && method_exists($wrappedClass, $methodName)) {
            /** @var class-string $wrappedClass */
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($wrappedClass), $methodName);
            $accepted = resolve(ReflectedTypeAcceptor::class)->accept($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        } elseif ($this->scope->modelClass !== null && method_exists($this->scope->modelClass, $methodName)) {
            // @mixin-style resources: `$this->resource->commentsCount()` lives on the model.
            /** @var class-string $modelClass */
            $modelClass = $this->scope->modelClass;
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($modelClass), $methodName);
            $accepted = resolve(ReflectedTypeAcceptor::class)->accept($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        }

        // On a date-cast receiver (e.g. `created_at`) the method is a Carbon instance method reached
        // through the cast, not declared on the model — reflect it on Carbon/CarbonImmutable instead.
        if ($expr->var instanceof PropertyFetch && $expr->var->name instanceof Identifier) {
            $receiverAttr = $this->scope->modelClass !== null
                ? resolve(ModelAttributeResolver::class)->getAttributes($this->scope->modelClass)
                    ?->firstWhere('name', $expr->var->name->toString())
                : null;

            $cast = $receiverAttr['cast'] ?? null;

            if (is_string($cast) && $this->isDateFamilyCast($cast)) {
                $carbonClass = str_starts_with($cast, 'immutable_')
                    ? CarbonImmutable::class
                    : Carbon::class;

                if (! $this->carbonMethodReturnsUnimportableStringable($carbonClass, $methodName)) {
                    $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(
                        new ReflectionClass($carbonClass),
                        $methodName,
                    );

                    $accepted = resolve(ReflectedTypeAcceptor::class)->accept($tsInfo);

                    if ($accepted !== null) {
                        return $accepted;
                    }
                }
            }
        }

        // Known-method rules — authorization checks and relation counts/existence.
        $known = $this->knownMethodRule($expr);

        if ($known !== null) {
            return $known;
        }

        return $result;
    }

    /**
     * Determine whether a Carbon(Immutable) method returns a __toString()-only class, not a genuine string.
     *
     * Needed since toTsType() erases Stringable classes to a bare `string` — mirrors step 5b's own condition.
     * Carbon/CarbonImmutable are excluded — their `__toString()` IS the canonical value, unlike CarbonInterval's.
     */
    protected function carbonMethodReturnsUnimportableStringable(string $carbonClass, string $methodName): bool
    {
        if (! method_exists($carbonClass, $methodName)) {
            return false;
        }

        $returnType = new ReflectionMethod($carbonClass, $methodName)->getReturnType();

        if (! $returnType instanceof ReflectionNamedType) {
            return false;
        }

        $name = $returnType->getName();

        if (in_array($name, [BaseCarbon::class, CarbonImmutable::class], true)) {
            return false;
        }

        return class_exists($name)
            && ! is_a($name, Model::class, true)
            && method_exists($name, '__toString');
    }

    /**
     * Determine whether a resolved model cast belongs to the date/datetime family, including
     * immutable_* variants and the `:format` suffix on custom_datetime casts.
     */
    protected function isDateFamilyCast(string $cast): bool
    {
        return in_array(explode(':', $cast)[0], [
            'date', 'datetime', 'custom_datetime', 'timestamp',
            'immutable_date', 'immutable_datetime', 'immutable_custom_datetime',
        ], true);
    }

    /**
     * Resolve a `$this->{name}` property as a model relation, in ModelAttributeResolver::resolveRelation()'s
     * {type, modelFqcn, morphFqcns} shape — a to-many relation's type ends in '[]'.
     *
     * @return array{type: string, modelFqcn: class-string<Model>|null, morphFqcns: list<class-string>}
     */
    protected function resolveModelRelationTypeInfo(string $name): array
    {
        if ($this->scope->modelClass === null) {
            return ['type' => 'unknown', 'modelFqcn' => null, 'morphFqcns' => []];
        }

        return resolve(ModelAttributeResolver::class)->resolveRelation($this->scope->modelClass, $name);
    }

    /**
     * Analyze a method-call chain on `$this->{manyRelation}` of identity-preserving ops plus at most
     * one `map()`/`pluck()`, or an argless `first()`/`last()`.
     *
     * Anything else returns null and falls through — e.g. `$this->items->count()` still reaches knownMethodRule().
     *
     * @return ValueExpressionResult|null
     */
    protected function analyzeRelationCollectionChain(MethodCall $call): ?array
    {
        $identityOps = [
            'take', 'skip', 'filter', 'reject', 'values', 'unique',
            'sortBy', 'sortByDesc', 'slice', 'reverse', 'where', 'whereNotNull',
            'load', 'loadMissing',
        ];

        // Walk down the chain collecting op names until we reach $this->prop.
        /** @var list<array{name: string, node: MethodCall}> $ops */
        $ops = [];
        $node = $call;

        while ($node instanceof MethodCall) {
            if (! $node->name instanceof Identifier) {
                return null;
            }

            $ops[] = ['name' => $node->name->toString(), 'node' => $node];
            $node = $node->var;
        }

        if (! $node instanceof PropertyFetch || ! $this->isThisPropertyFetch($node) || ! $node->name instanceof Identifier) {
            return null;
        }

        $relationInfo = $this->resolveModelRelationTypeInfo($node->name->toString());

        if (! str_ends_with($relationInfo['type'], '[]') || $relationInfo['modelFqcn'] === null) {
            return null;
        }

        $elementModel = $relationInfo['modelFqcn'];

        // first()/last() as the outermost op terminate the chain with a single element or null. $ops[0]
        // is the outermost call because the walk above collects outside-in, and always exists here: the
        // while loop above ran at least once, since $call is typed as MethodCall.
        $terminalOp = $ops[0]['name'];
        $isTerminal = ($terminalOp === 'first' || $terminalOp === 'last')
            && ! $ops[0]['node']->isFirstClassCallable()
            && $ops[0]['node']->getArgs() === [];

        if ($isTerminal) {
            array_shift($ops);
        }

        $mapNode = null;
        $pluckNode = null;

        // A relation collection starts keyed 0..n-1; each op below says whether that still holds.
        $sequentialKeys = true;

        foreach (array_reverse($ops) as $op) {
            if (in_array($op['name'], $identityOps, true)) {
                $sequentialKeys = match ($op['name']) {
                    'values' => true,
                    'take' => $sequentialKeys && $this->isFrontAnchoredTake($op['node']),
                    'load', 'loadMissing' => $sequentialKeys,
                    default => false,
                };

                continue;
            }

            if ($op['name'] === 'map' && $mapNode === null && $pluckNode === null) {
                // map() preserves the receiver's keys, so it neither breaks nor restores sequentiality.
                $mapNode = $op['node'];

                continue;
            }

            if ($op['name'] === 'pluck' && $pluckNode === null && $mapNode === null) {
                $pluckNode = $op['node'];
                $sequentialKeys = $op['node']->isFirstClassCallable() || count($op['node']->getArgs()) < 2;

                continue;
            }

            // Unsupported op, including a 2nd map()/pluck() or map()+pluck() combined.
            return null;
        }

        if ($isTerminal) {
            if ($mapNode !== null || $pluckNode !== null) {
                return null; // YAGNI: map()/pluck() combined with a first()/last() terminal.
            }

            return [
                ...$this->unknownResult(),
                'type' => class_basename($elementModel).' | null',
                'optional' => false,
                'modelFqcn' => $elementModel,
            ];
        }

        if ($mapNode === null && $pluckNode === null) {
            return [
                ...$this->unknownResult(),
                'type' => $sequentialKeys ? $relationInfo['type'] : $this->keyedObjectArm($relationInfo['type']),
                'optional' => false,
                'modelFqcn' => $elementModel,
            ];
        }

        if ($pluckNode !== null) {
            // First-class callable syntax (`->pluck(...)`) has no args: CallLike::getArgs() asserts
            // !isFirstClassCallable() and throws AssertionError under zend.assertions=1 (PHP's dev
            // default), and analyzeVariablePluckCall() calls getArgs() unconditionally.
            if ($pluckNode->isFirstClassCallable()) {
                return null;
            }

            $previousContext = $this->scope->closureRelationModelClass;
            $this->scope->closureRelationModelClass = $elementModel;

            try {
                $pluckResult = $this->analyzeVariablePluckCall($pluckNode);
            } finally {
                $this->scope->closureRelationModelClass = $previousContext;
            }

            // analyzeVariablePluckCall() degrades an unresolved field to 'unknown[]'; normalize to null
            // so the caller's fallthrough produces plain 'unknown' like every other unrecognized chain.
            if ($pluckResult['type'] === 'unknown[]') {
                return null;
            }

            if (! $sequentialKeys) {
                $pluckResult['type'] = $this->keyedObjectArm($pluckResult['type']);
            }

            return [...$this->unknownResult(), ...$pluckResult];
        }

        // The map argument must be a Closure/ArrowFunction: a callable-array (`[$this, 'method']`) or a
        // bare string callable (`'strtoupper'`) is itself a valid expression node, so analyzeValueExpression()
        // would resolve *that* — 'strtoupper' → 'string', wrongly wrapped here to 'string[]'.
        /** @var MethodCall $mapNode */
        // First-class callable syntax (`->map(...)`) has no args: getArgs() throws AssertionError under
        // zend.assertions=1 rather than returning [].
        if ($mapNode->isFirstClassCallable()) {
            return null;
        }

        $args = $mapNode->getArgs();

        if ($args === []) {
            return null; // @codeCoverageIgnore
        }

        $mapArg = $args[0]->value;

        if (! $mapArg instanceof ArrowFunction && ! $mapArg instanceof ClosureExpr) {
            return null;
        }

        $previousContext = $this->scope->closureRelationModelClass;
        $previousVarModelBindings = $this->scope->varModelBindings;
        $this->scope->closureRelationModelClass = $elementModel;

        if ($mapArg->params !== []
            && $mapArg->params[0]->var instanceof Variable
            && is_string($mapArg->params[0]->var->name)
        ) {
            $this->scope->varModelBindings[$mapArg->params[0]->var->name] = $elementModel;
        }

        try {
            $bodyResult = $this->analyzeValueExpression($mapArg);
        } finally {
            $this->scope->closureRelationModelClass = $previousContext;
            $this->scope->varModelBindings = $previousVarModelBindings;
        }

        if ($bodyResult['type'] === 'unknown') {
            return null;
        }

        // A map body entirely `EnumResource::make(...)` carries a live 'enumFqcn' through; the
        // transformer's substitution-based rewrite reproduces whatever shape results, including
        // the keyed Record arm a non-sequential filter()/sortBy() introduces.
        $mapped = $this->arrayWrapType($bodyResult['type']);

        return [
            ...$bodyResult,
            'type' => $sequentialKeys ? $mapped : $this->keyedObjectArm($mapped),
            'optional' => false,
        ];
    }

    /**
     * Whether a `take()` call slices from the front, where a sequentially keyed receiver stays sequential.
     *
     * A negative count takes from the tail and a non-literal count could be either, so both are rejected.
     */
    private function isFrontAnchoredTake(MethodCall $call): bool
    {
        if ($call->isFirstClassCallable()) {
            return false;
        }

        $args = $call->getArgs();

        return count($args) === 1 && $args[0]->value instanceof Int_;
    }

    /**
     * Add the object arm json_encode emits for a gapped or reordered collection: `X[]` → `X[] | Record<string, X>`.
     */
    private function keyedObjectArm(string $arrayType): string
    {
        return $arrayType.' | Record<string, '.substr($arrayType, 0, -2).'>';
    }

    /**
     * Suffix a type with `[]`, parenthesizing a union or intersection first: TypeScript binds `[]`
     * tighter than both, so `A & B[]` parses as `A & (B[])`, not `(A & B)[]`.
     */
    private function arrayWrapType(string $type): string
    {
        return str_contains($type, '|') || str_contains($type, '&') ? '('.$type.')[]' : $type.'[]';
    }

    /**
     * Replace a bare enum type-name token with its AsEnum wrap, preserving every other union arm.
     *
     * Mirrors ResourceTransformer::substituteEnumResourceType(): the lookbehind's `.` keeps a
     * namespace-qualified `foo.RoleType` unmatched, the lookahead keeps `RoleTypeExtra` unmatched.
     */
    private function substituteEnumType(string $typeStr, string $bareTypeName, string $asEnumType): string
    {
        $pattern = '/(?<![A-Za-z0-9_$.])'.preg_quote($bareTypeName, '/').'(?![A-Za-z0-9_$])/';

        return preg_replace($pattern, $asEnumType, $typeStr) ?? $typeStr;
    }

    /**
     * Rejoin a mixed wrap/direct enum union's split members, naming the wrapped arm without
     * losing the direct one.
     *
     * An array-shaped member is the arm EnumResource::collection() forced, so it substitutes and the
     * bare member stays as the direct arm. With no such member both arms rendered the same token and
     * deduped to one, so the wrapped arm is spelled out beside it instead of overwriting it.
     *
     * @param  list<string>  $members
     */
    private function expandMixedEnumType(array $members, string $bareTypeName, string $asEnumType): string
    {
        $collectionType = $bareTypeName.'[]';
        $hasCollectionArm = in_array($collectionType, $members, true);

        $expanded = array_map(fn (string $member): string => match (true) {
            $member === $collectionType => $asEnumType.'[]',
            ! $hasCollectionArm && $member === $bareTypeName => $asEnumType.' | '.$bareTypeName,
            default => $member,
        }, $members);

        return implode(' | ', $expanded);
    }

    /**
     * Late-stage rules fixed by Laravel convention (can/cannot/canAny → boolean; count/exists → number/boolean).
     *
     * count()/exists()/getKey() are receiver-gated (unlike can()) since those names are commonly overloaded;
     * getKey() is further scoped to `$this->resource->getKey()` since its type depends on the receiver's key type.
     *
     * @return ValueExpressionResult|null
     */
    protected function knownMethodRule(MethodCall|NullsafeMethodCall $expr): ?array
    {
        if (! $expr->name instanceof Identifier) {
            return null; // @codeCoverageIgnore
        }

        $method = $expr->name->toString();

        if (in_array($method, ['can', 'cannot', 'canAny'], true)) {
            return [...$this->unknownResult(), 'type' => 'boolean', 'optional' => false];
        }

        if ($method === 'getKey') {
            $isResourceReceiver = $expr->var instanceof PropertyFetch
                && $this->isThisPropertyFetch($expr->var)
                && $expr->var->name instanceof Identifier
                && $expr->var->name->toString() === 'resource';

            if (! $isResourceReceiver || $this->scope->modelClass === null) {
                return null;
            }

            $instance = resolve(ModelAttributeResolver::class)->getInstance($this->scope->modelClass);

            $type = $instance?->getKeyType() === 'int' ? 'number' : 'string';

            return [...$this->unknownResult(), 'type' => $type, 'optional' => false];
        }

        if (! in_array($method, ['count', 'exists'], true)) {
            return null;
        }

        // Receiver must be $this->{manyRelation}, or $this->collection on a ResourceCollection —
        // Laravel populates that property with the collected resources, always a many receiver.
        if ($expr->var instanceof PropertyFetch
            && $this->isThisPropertyFetch($expr->var)
            && $expr->var->name instanceof Identifier
        ) {
            $propName = $expr->var->name->toString();

            $isManyReceiver = ($propName === 'collection' && $this->isResourceCollection())
                || str_ends_with($this->resolveModelRelationTypeInfo($propName)['type'], '[]');

            if ($isManyReceiver) {
                return [
                    ...$this->unknownResult(),
                    'type' => $method === 'count' ? 'number' : 'boolean',
                    'optional' => false,
                ];
            }
        }

        return null;
    }

    /**
     * Determine the TypeScript type for a backed enum's `->value` property from its backing type.
     */
    protected function resolveEnumValueBackingType(): string
    {
        $wrappedClass = $this->resolveWrappedClass();

        if ($wrappedClass !== null && enum_exists($wrappedClass)) {
            $r = new ReflectionEnum($wrappedClass);
            $backingType = $r->getBackingType();

            if ($backingType !== null) {
                return $backingType->getName() === 'string' ? 'string' : 'number';
            }
        }

        return 'string | number';
    }

    /**
     * Analyze all direct Return_ statements yielding non-empty Array_ literals, merging multiple
     * branches with union semantics: properties present in only some branches become optional.
     *
     * @param  array<Node\Stmt>  $stmts
     */
    protected function analyzeAllReturnBranches(array $stmts): ?ResourceAnalysis
    {
        /** @var list<Return_> $candidates */
        $candidates = [];

        $this->collectDirectReturns($stmts, $candidates);

        // Filter out empty array returns (guard clauses like `return []`)
        $candidates = array_values(array_filter($candidates, function (Return_ $r): bool {
            return $r->expr instanceof Array_ && count($r->expr->items) > 0;
        }));

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) === 1) {
            /** @var Array_ $expr */
            $expr = $candidates[0]->expr;

            return $this->analyzeReturnArray($expr);
        }

        $analyses = array_map(function (Return_ $r) {
            /** @var Array_ $expr */
            $expr = $r->expr;

            return $this->analyzeReturnArray($expr);
        }, $candidates);

        return $this->mergeReturnBranches($analyses);
    }

    /**
     * Merge ResourceAnalysis objects from different return branches: a property missing from any
     * branch becomes optional, every map channel unions per key — inlineModelFqcns per occurrence,
     * the enum maps deduped — and flatTypeAlias/flatTypeAliasFqcn keep the first non-null branch value.
     *
     * @param  list<ResourceAnalysis>  $analyses
     */
    protected function mergeReturnBranches(array $analyses): ResourceAnalysis
    {
        $branchCount = count($analyses);

        /** @var array<string, list<array{type: string, optional: bool, description: string}>> */
        $propertyMap = [];

        $enumResources = [];
        $nestedResources = [];
        $directEnumFqcns = [];
        $modelFqcns = [];
        $customImports = [];
        /** @var MultiEnumFqcnsMap $multiEnumResourceFqcns */
        $multiEnumResourceFqcns = [];
        /** @var InlineEnumFqcnsMap $inlineEnumFqcns */
        $inlineEnumFqcns = [];
        /** @var InlineModelFqcnsMap $inlineModelFqcns */
        $inlineModelFqcns = [];
        /** @var InlineEnumFqcnsMap $inlineEnumResourceFqcns */
        $inlineEnumResourceFqcns = [];
        $flatTypeAlias = null;
        $flatTypeAliasFqcn = null;

        foreach ($analyses as $analysis) {
            foreach ($analysis->properties as $prop) {
                $propertyMap[$prop['name']][] = $prop;
            }

            $enumResources = [...$enumResources, ...$analysis->enumResources];
            $nestedResources = [...$nestedResources, ...$analysis->nestedResources];
            $directEnumFqcns = [...$directEnumFqcns, ...$analysis->directEnumFqcns];
            $modelFqcns = [...$modelFqcns, ...$analysis->modelFqcns];
            $multiEnumResourceFqcns = [...$multiEnumResourceFqcns, ...$analysis->multiEnumResourceFqcns];
            $flatTypeAlias ??= $analysis->flatTypeAlias;
            $flatTypeAliasFqcn ??= $analysis->flatTypeAliasFqcn;

            foreach ($analysis->customImports as $path => $names) { // @codeCoverageIgnoreStart
                $customImports[$path] = array_values(array_unique([
                    ...($customImports[$path] ?? []),
                    ...$names,
                ]));
            } // @codeCoverageIgnoreEnd

            foreach ($analysis->inlineEnumFqcns as $propName => $fqcns) {
                $inlineEnumFqcns[$propName] = array_values(array_unique(
                    [...($inlineEnumFqcns[$propName] ?? []), ...$fqcns]
                ));
            }

            foreach ($analysis->inlineModelFqcns as $propName => $fqcns) {
                $inlineModelFqcns[$propName] = [...($inlineModelFqcns[$propName] ?? []), ...$fqcns];
            }

            foreach ($analysis->inlineEnumResourceFqcns as $propName => $fqcns) {
                $inlineEnumResourceFqcns[$propName] = array_values(array_unique(
                    [...($inlineEnumResourceFqcns[$propName] ?? []), ...$fqcns]
                ));
            }
        }

        /** @var list<array{name: string, type: string, optional: bool, description: string}> */
        $properties = [];

        foreach ($propertyMap as $name => $entries) {
            $types = array_values(array_unique(array_column($entries, 'type')));
            $type = count($types) === 1 ? $types[0] : $this->unionBranchTypes($types);

            $presentInAll = count($entries) === $branchCount;
            $anyOptional = (bool) array_filter($entries, fn (array $e) => $e['optional']);
            $optional = ! $presentInAll || $anyOptional;

            // Use the first non-empty description found
            $description = '';

            foreach ($entries as $entry) {
                if ($entry['description'] !== '') { // @codeCoverageIgnoreStart
                    $description = $entry['description'];

                    break; // @codeCoverageIgnoreEnd
                }
            }

            $properties[] = [
                'name' => $name,
                'type' => $type,
                'optional' => $optional,
                'description' => $description,
            ];
        }

        return new ResourceAnalysis(
            properties: $properties,
            enumResources: $enumResources,
            nestedResources: $nestedResources,
            customImports: $customImports,
            directEnumFqcns: $directEnumFqcns,
            modelFqcns: $modelFqcns,
            inlineEnumFqcns: $inlineEnumFqcns,
            inlineModelFqcns: $inlineModelFqcns,
            multiEnumResourceFqcns: $multiEnumResourceFqcns,
            inlineEnumResourceFqcns: $inlineEnumResourceFqcns,
            flatTypeAlias: $flatTypeAlias,
            flatTypeAliasFqcn: $flatTypeAliasFqcn,
        );
    }

    /**
     * Union branch type strings, hoisting one trailing `| null` rather than repeating the marker each
     * nullsafe branch already carries. Only top-level nulls move; a nested one belongs to its member.
     *
     * @param  list<string>  $types
     */
    private function unionBranchTypes(array $types): string
    {
        $members = [];
        $nullable = false;

        foreach ($types as $type) {
            foreach (LaravelTsPublish::splitTopLevelUnion($type) as $member) {
                if ($member === 'null') {
                    $nullable = true;

                    continue;
                }

                $members[] = $member;
            }
        }

        if ($nullable) {
            $members[] = 'null';
        }

        return implode(' | ', $members);
    }

    /**
     * Recursively collect Return_ statements with Array_ expressions from
     * the given statements, descending into control-flow structures (if, foreach, etc.)
     * but NOT into closures, arrow functions, or anonymous classes.
     *
     * @param  array<Node\Stmt|Node>  $stmts
     * @param  list<Return_>  $candidates
     */
    protected function collectDirectReturns(array $stmts, array &$candidates): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Return_ && $stmt->expr instanceof Array_) {
                $candidates[] = $stmt;

                continue;
            }

            if ($stmt instanceof If_) {
                $this->collectDirectReturns($stmt->stmts, $candidates);

                foreach ($stmt->elseifs as $elseif) {
                    $this->collectDirectReturns($elseif->stmts, $candidates);
                }

                if ($stmt->else !== null) {
                    $this->collectDirectReturns($stmt->else->stmts, $candidates);
                }

                continue;
            }

            if ($stmt instanceof Foreach_ || $stmt instanceof For_ || $stmt instanceof While_ || $stmt instanceof Do_) {
                $this->collectDirectReturns($stmt->stmts, $candidates);
            }

            // Do NOT descend into closures, arrow functions, or anonymous classes
        }
    }

    /**
     * Extract an instanceof type hint from a guard clause in toArray(), positive or negated —
     * e.g. `if (! $this->resource instanceof ClassName) { return []; }`.
     *
     * @return class-string|null
     */
    protected function resolveInstanceOfType(ClassMethod $method, NodeFinder $finder): ?string
    {
        /** @var list<If_> $ifNodes */
        $ifNodes = $finder->find($method->stmts ?? [], function (Node $node): bool {
            return $node instanceof If_;
        });

        foreach ($ifNodes as $ifNode) {
            $cond = $ifNode->cond;

            // Match: if (!$this->resource instanceof ClassName)
            if ($cond instanceof BooleanNot && $cond->expr instanceof Instanceof_) {
                $instanceOf = $cond->expr;
            } elseif ($cond instanceof Instanceof_) {
                // Match: if ($this->resource instanceof ClassName) — positive guard
                $instanceOf = $cond;
            } else {
                continue;
            }

            // Verify it's checking $this->resource
            if (! ($instanceOf->expr instanceof PropertyFetch
                && $instanceOf->expr->var instanceof Variable
                && $instanceOf->expr->var->name === 'this'
                && $instanceOf->expr->name instanceof Identifier
                && $instanceOf->expr->name->toString() === 'resource')) {
                continue; // @codeCoverageIgnore
            }

            if (! $instanceOf->class instanceof Name) {
                continue; // @codeCoverageIgnore
            }

            // After NameResolver traversal, the class name is already a FQCN
            $fqcn = $instanceOf->class->toString();

            if (class_exists($fqcn) || enum_exists($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }

    /**
     * Resolve the wrapped class for this resource, falling back to the instanceof guard clause hint.
     *
     * @return class-string|null
     */
    protected function resolveWrappedClass(): ?string
    {
        return $this->resolveClassOnProperty($this->scope->subjectReflection) ?? $this->scope->instanceOfWrappedClass;
    }

    /**
     * Analyze `$variable->map(fn (TypedClass $item) => [...])` using the closure's typed first param
     * as the element model, wrapping the body result as `elementType[]`.
     *
     * Returns null when there's no typed Model parameter, deferring to the generic method handler.
     *
     * @return ValueExpressionResult|null
     */
    private function analyzeVariableMapCall(MethodCall $call): ?array
    {
        $args = $call->getArgs();

        if ($args === []) {
            return null;
        }

        $closureArg = $args[0]->value;

        if ($closureArg instanceof ArrowFunction) {
            $params = $closureArg->params;
        } elseif ($closureArg instanceof ClosureExpr) {
            $params = $closureArg->params;
        } else {
            return null;
        }

        if ($params === []) {
            return null;
        }

        $firstParam = $params[0];

        // A named class type hint (already FQCN-resolved by NameResolver) wins when present — it's
        // the more specific signal. Otherwise fall back to the receiver's own relation binding, the
        // same one ConditionalMethodHandler::analyzeWhenLoaded() already populated for a to-many param.
        $paramClass = $firstParam->type instanceof Name
            ? $firstParam->type->toString()
            : $this->resolveMapProxyElementModel($call->var);

        if ($paramClass === null || ! class_exists($paramClass) || ! is_a($paramClass, Model::class, true)) {
            return null;
        }

        /** @var class-string<Model> $paramClass */
        $previousRelationModel = $this->scope->closureRelationModelClass;
        $this->scope->closureRelationModelClass = $paramClass;

        $returnExprs = $this->resolveClosureReturnExpressions($closureArg);

        $bodyResult = match (count($returnExprs)) {
            0 => null,
            1 => $this->analyzeValueExpression($returnExprs[0]),
            default => (new ClosureHandler)->analyzeClosureUnion($returnExprs, $this),
        };

        $this->scope->closureRelationModelClass = $previousRelationModel;

        if ($bodyResult === null || $bodyResult['type'] === 'unknown') {
            return null;
        }

        // arrayWrapType(), not a raw '[]' suffix: a union body (e.g. a mixed AsEnum/direct-enum
        // ternary) must be parenthesized before the array suffix binds.
        $bodyResult['type'] = $this->arrayWrapType($bodyResult['type']);
        $bodyResult['optional'] = false;

        return $bodyResult;
    }

    /**
     * Analyze a `$variable->pluck('field')` call within a whenLoaded closure context.
     *
     * Returns `unknown[]`, not `unknown`, when the field type cannot be determined — callers that
     * only test for a non-`unknown` result rely on that.
     *
     * @return ValueExpressionResult
     */
    private function analyzeVariablePluckCall(MethodCall $call): array
    {
        $args = $call->getArgs();

        if (count($args) >= 1 && $args[0]->value instanceof String_) {
            $fieldName = $args[0]->value->value;
            $info = $this->analyzeRelatedModelProperty($fieldName, $this->scope);

            if ($info['type'] !== 'unknown') {
                $info['type'] = $this->arrayWrapType($info['type']);
                $info['optional'] = false;

                return $info;
            }
        }

        return ['type' => 'unknown[]', 'optional' => false];
    }

    /**
     * Fallback result for expressions that can't be analyzed or have no type information.
     *
     * @return ValueExpressionResult
     */
    protected function unknownResult(): array
    {
        return ValueResult::unknown();
    }
}
