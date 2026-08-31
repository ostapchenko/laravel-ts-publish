<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Analyzers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\ChecksPreserveKeys;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\FiltersModelAttributes;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\ResolvesModelTypes;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\ExpressionDispatcher;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\BinaryOpHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\CastHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ConstFetchHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\FirstClassCallableHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ScalarHandler;
use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;
use AbeTwoThree\LaravelTsPublish\Ast\MethodLocator;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use AbeTwoThree\LaravelTsPublish\Attributes\TsCasts;
use AbeTwoThree\LaravelTsPublish\Cache\DependencyRecorder;
use AbeTwoThree\LaravelTsPublish\Cache\PublishedResourceRegistry;
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
use Illuminate\Support\Str;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
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
use Throwable;
use UnitEnum;

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
 * @phpstan-type ClosureAnnotationResult = array{
 *      type: string,
 *      directEnumFqcn?: class-string,
 *      modelFqcn?: class-string
 * }
 * @phpstan-type InlineSpreadArm = array{fqcn: class-string, isModel: bool, isCollection: bool}
 */
class ResourceAstAnalyzer implements ExpressionEngine
{
    use ChecksPreserveKeys;
    use FiltersModelAttributes;
    use InspectsAstNodes;
    use ResolvesClassNames;
    use ResolvesModelTypes;

    /** Total-element cap for a class constant's array before it bails to unknown; see constantArrayWithinLimits(). */
    private const int MAX_CONSTANT_ARRAY_ELEMENTS = 200;

    /** Nesting-depth cap for a class constant's array before it bails to unknown; see constantArrayWithinLimits(). */
    private const int MAX_CONSTANT_ARRAY_DEPTH = 5;

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
            new BinaryOpHandler,
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

        // SomeClass::CONSTANT / self::CONSTANT / static::CONSTANT as a value. `Foo::class` and
        // enum-case fetches are excluded inside the helper, so this never diverts those paths
        // (EnumResource::make(), toResource(SomeResource::class), #[Collects]).
        if ($expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            $constantResult = $this->resolveClassConstantValueExpression($expr);

            if ($constantResult !== null) {
                return $constantResult;
            }
        }

        if ($expr instanceof BinaryOp\Coalesce) {
            return $this->analyzeCoalesce($expr);
        }

        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            $tsType = $this->resolveKnownFunctionCallType($expr->name->getLast());

            if ($tsType !== null) {
                return ['type' => $tsType, 'optional' => false];
            }
        }

        // Closures / arrow functions — body analysis first, return-type annotation as the fallback.
        $closureReturns = $this->resolveClosureReturnExpressions($expr);

        if ($closureReturns !== []) {
            // A param merely shadows a same-named outer local for this body — it must not resolve
            // through the outer binding just because no scoped binding (e.g. whenLoaded) claimed it.
            $previousLocalVarBindings = $this->scope->localVarBindings;

            if ($expr instanceof ArrowFunction || $expr instanceof ClosureExpr) {
                foreach ($expr->params as $param) {
                    if ($param->var instanceof Variable && is_string($param->var->name)) {
                        unset($this->scope->localVarBindings[$param->var->name]);
                    }
                }
            }

            try {
                $bodyResult = count($closureReturns) === 1
                    ? $this->analyzeValueExpression($closureReturns[0])
                    : $this->analyzeClosureUnion($closureReturns);

                if ($bodyResult['type'] !== 'unknown') {
                    return $bodyResult;
                }

                $annotationResult = $this->resolveClosureAstReturnType($expr);

                if ($annotationResult !== null) {
                    return [...$annotationResult, 'optional' => false];
                }

                return $bodyResult;
            } finally {
                $this->scope->localVarBindings = $previousLocalVarBindings;
            }
        }

        if ($this->isThisMethodCall($expr, 'when')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhen($expr);
        }

        // unless() delegates to when() unchanged: negating the condition changes which arm runs,
        // never what either arm's type is.
        if ($this->isThisMethodCall($expr, 'unless')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhen($expr);
        }

        if ($this->isThisMethodCall($expr, 'whenAppended')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenAppended($expr);
        }

        if ($this->isThisMethodCall($expr, 'whenExistsLoaded')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenExistsLoaded($expr);
        }

        if ($this->isThisMethodCall($expr, 'transform')) {
            /** @var MethodCall $expr */
            return $this->analyzeTransform($expr);
        }

        if ($this->isThisMethodCall($expr, 'whenHas')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenHas($expr);
        }

        if ($this->isThisMethodCall($expr, 'whenNotNull')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenNotNull($expr);
        }

        if ($this->isThisMethodCall($expr, 'whenNull')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenNull($expr);
        }

        if ($this->isThisMethodCall($expr, 'whenLoaded')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenLoaded($expr);
        }

        if ($this->isThisMethodCall($expr, 'whenCounted')) {
            /** @var MethodCall $expr */
            return $this->applyConditionalDefault(['type' => 'number', 'optional' => false], $expr, 2);
        }

        if ($this->isThisMethodCall($expr, 'whenAggregated')) {
            /** @var MethodCall $expr */
            return $this->applyConditionalDefault(['type' => 'number', 'optional' => false], $expr, 4);
        }

        if ($this->isThisMethodCall($expr, 'whenPivotLoaded')) {
            /** @var MethodCall $expr */
            return $this->applyConditionalDefault($this->unknownResult(), $expr, 2);
        }

        if ($this->isThisMethodCall($expr, 'whenPivotLoadedAs')) {
            /** @var MethodCall $expr */
            return $this->applyConditionalDefault($this->unknownResult(), $expr, 3);
        }

        // $model->toResource()/toResourceCollection() — a whenLoaded closure param bound to a
        // model, or $this->relation accessed directly. Checked by method name alone so both
        // receiver shapes share one resolution path; see resolveToResourceReceiverModel().
        if ($expr instanceof MethodCall && $expr->name instanceof Identifier && $expr->name->toString() === 'toResource') {
            return $this->analyzeToResourceCall($expr);
        }

        if ($expr instanceof MethodCall
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'toResourceCollection'
        ) {
            return $this->analyzeToResourceCollectionCall($expr);
        }

        // `$variable::staticMethod()` in a whenLoaded closure. Must precede the general StaticCall
        // handler, which only matches class-name receivers.
        if ($this->scope->closureRelationModelClass !== null
            && $expr instanceof StaticCall
            && $expr->class instanceof Variable
            && is_string($expr->class->name)
            && $expr->class->name !== 'this'
            && $expr->name instanceof Identifier
        ) {
            return $this->analyzeRelatedModelMethodCall($expr->name->toString());
        }

        // SomeResource::collection(...)->resolve() — strip the trailing ->resolve() and delegate.
        if ($expr instanceof MethodCall
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'resolve'
            && $expr->var instanceof StaticCall
        ) {
            return $this->analyzeStaticCall($expr->var);
        }

        // A fluent method chained onto a resource-resolving receiver — `new self($x)->foo()`,
        // `SomeResource::make($x)->foo()`, or a chain of such calls — keeps the receiver's type
        // when the method's own declared return type hands the same instance back.
        if ($expr instanceof MethodCall
            && $expr->name instanceof Identifier
            && ($expr->var instanceof New_ || $expr->var instanceof StaticCall || $expr->var instanceof MethodCall)
        ) {
            $selfReturning = $this->analyzeSelfReturningResourceMethodCall($expr);

            if ($selfReturning !== null) {
                return $selfReturning;
            }
        }

        // `$this::staticMethod()` — the resource itself is the receiver.
        if ($expr instanceof StaticCall
            && $expr->class instanceof Variable
            && $expr->class->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            return $this->analyzeThisMethodCall($expr->name->toString());
        }

        // `$this->resource::staticMethod()`. Must precede the closure-context PropertyFetch handler below.
        if ($expr instanceof StaticCall
            && $expr->class instanceof PropertyFetch
            && $expr->class->var instanceof Variable
            && $expr->class->var->name === 'this'
            && $expr->class->name instanceof Identifier
            && $expr->class->name->toString() === 'resource'
            && $expr->name instanceof Identifier
        ) {
            return $this->analyzeStaticMethodOnResource($expr->name->toString());
        }

        // `$this->relation::staticMethod()` inside a whenLoaded closure — use the related model.
        if ($expr instanceof StaticCall
            && $expr->class instanceof PropertyFetch
            && $expr->name instanceof Identifier
        ) {
            /** @var class-string<Model>|null $closureModelClass */
            $closureModelClass = $this->scope->closureRelationModelClass;

            if ($closureModelClass !== null) {
                return $this->analyzeRelatedModelMethodCall($expr->name->toString());
            }
        }

        // EnumResource::make($this->prop) or SomeResource::make/collection()
        if ($expr instanceof StaticCall) {
            return $this->analyzeStaticCall($expr);
        }

        if ($expr instanceof New_) {
            return $this->analyzeNewResource($expr);
        }

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
                $info = $this->analyzeRelatedModelProperty($expr->name->toString());
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
                $info = $this->analyzeRelatedModelMethodCall($expr->name->toString());
            }

            return $info;
        }

        // Generic `$this->method()` — reflect the declared return type; the helper guards above ran first.
        if ($expr instanceof MethodCall
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            return $this->analyzeThisMethodCall($expr->name->toString());
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
                return $this->analyzeRelatedModelProperty($expr->name->toString(), $boundModel);
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
                return $this->analyzeRelatedModelMethodCall($expr->name->toString(), $boundModel);
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

        // Bare variable bound either to a closure parameter (bindClosureParamsFromCondition) or to a
        // top-level local assignment (collectLocalVarBindings). Closure-param bindings win, being the
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
     * Resolve a PHP built-in function name to its TypeScript return type, or null when unresolvable.
     */
    private function resolveKnownFunctionCallType(string $name): ?string
    {
        $tsInfo = LaravelTsPublish::nativePhpFunctionReturnedTypes($name);

        return ! str_contains($tsInfo['type'], 'unknown') ? $tsInfo['type'] : null;
    }

    /**
     * Analyze a null-coalescing expression (`$left ?? $right`).
     *
     * Doesn't delegate to analyzeClosureUnion(): that would leave `null` in twice (`Order | null | Order`).
     * Only operands contributing a result member get their FQCN/import channels merged.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeCoalesce(BinaryOp\Coalesce $expr): array
    {
        $leftResult = $this->analyzeValueExpression($expr->left);
        $rightResult = $this->analyzeValueExpression($expr->right);

        $leftType = $leftResult['type'];
        $rightType = $rightResult['type'];

        // Strip `| null` from the left: with a non-null fallback, null is never the final result.
        $leftType = $this->stripNullArm($leftType);

        if ($leftType === 'unknown' || $leftType === '') {
            return $this->mergeUnionChannels([$rightType], [$rightResult]);
        }

        if ($rightType === 'unknown') {
            return $this->mergeUnionChannels([$leftType], [$leftResult]);
        }

        if ($leftType === $rightType) {
            return $this->mergeUnionChannels([$leftType], [$leftResult, $rightResult]);
        }

        return $this->mergeUnionChannels([$leftType, $rightType], [$leftResult, $rightResult]);
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
            return $this->analyzeClosureUnion([$expr->cond, $expr->else]);
        }

        return $this->analyzeClosureUnion([$expr->if, $expr->else]);
    }

    /**
     * Fold a conditional method's explicit default into its value arm's result.
     *
     * An explicit default always makes the property required, since Laravel then always emits the key.
     * The default's type unions in when it resolves; otherwise the value arm's own type stands alone.
     * $defaultArgCount is how many arguments Laravel invokes the default with — 0 for the value($default)
     * family, 1 for transform()'s $default($value) — and is forwarded to closureRequiresArguments().
     *
     * @param  ValueExpressionResult  $value
     * @return ValueExpressionResult
     */
    protected function applyConditionalDefault(array $value, MethodCall $call, int $index, int $defaultArgCount = 0): array
    {
        if (! $this->hasExplicitDefaultArg($call, $index)) {
            return [...$value, 'optional' => true];
        }

        $defaultExpr = $call->getArgs()[$index]->value;

        // A default closure requiring more parameters than Laravel supplies it can never run, so its
        // arm is unreachable — the value arm stands alone, still required.
        if ($this->closureRequiresArguments($defaultExpr, $defaultArgCount)) {
            return [...$value, 'optional' => false];
        }

        $default = $this->analyzeValueExpression($defaultExpr);

        // An `unknown` on either arm carries no type to union: an unresolved default leaves the value arm
        // standing, and an unresolved value arm already admits whatever the default could produce.
        if ($default['type'] === 'unknown' || $value['type'] === 'unknown') {
            return [...$value, 'optional' => false];
        }

        $members = array_values(array_unique([
            ...explode(' | ', $value['type']),
            ...explode(' | ', $default['type']),
        ]));

        // `[]` is assignable to every array type, so an empty-array arm beside a real one would only
        // widen the property into a shape — `Category[] | Record<…>` — that no caller can consume.
        if (array_any($members, fn (string $m): bool => $m !== 'never[]' && str_ends_with($m, '[]'))) {
            $members = array_values(array_filter($members, fn (string $m): bool => $m !== 'never[]'));
        }

        return [...$this->mergeUnionChannels($members, [$value, $default]), 'optional' => false];
    }

    /**
     * Analyze $this->when(condition, value) — the value is the second arg.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhen(MethodCall $call): array
    {
        $result = $this->unknownResult();
        $args = $call->getArgs();

        if (count($args) >= 2) {
            $valueExpr = $args[1]->value;

            $previousBindings = $this->scope->closureParamExprBindings;
            $this->bindClosureParamsFromCondition($args[0]->value, $valueExpr);

            $inner = $this->analyzeValueExpression($valueExpr);

            $this->scope->closureParamExprBindings = $previousBindings;

            return $this->applyConditionalDefault($inner, $call, 2);
        }

        return [...$result, 'optional' => true]; // @codeCoverageIgnore
    }

    /**
     * Analyze $this->whenHas('attribute') — the attribute name is the first arg string.
     *
     * The value arg (2nd) is never evaluated for its own type: Laravel invokes it with the named
     * attribute's own value, so the attribute is authoritative for type and array-ness. It IS
     * checked for EnumResource::make()/::collection() shape, since that decides whether the enum
     * channel is 'enumFqcn' (wrapped — gets the AsEnum rewrite) or 'directEnumFqcn' (read as-is).
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenHas(MethodCall $call): array
    {
        $result = $this->unknownResult();
        $args = $call->getArgs();

        if (count($args) >= 1 && $args[0]->value instanceof String_) {
            $attrName = $args[0]->value->value;
            $info = $this->resolveModelAttributeTypeInfo($attrName);
            $result = ['type' => $info['type'], 'optional' => false];

            if ($info['enumFqcn'] !== null) {
                $wrapped = count($args) >= 2 && $this->isEnumResourceWrapCall($args[1]->value);
                $result[$wrapped ? 'enumFqcn' : 'directEnumFqcn'] = $info['enumFqcn'];
            }

            return $this->applyConditionalDefault($result, $call, 2);
        }

        return [...$result, 'optional' => true]; // @codeCoverageIgnore
    }

    /**
     * Analyze $this->whenAppended('attribute', $value, $default) — types from the named attribute,
     * the same way whenHas() does, since the appended accessor is what surfaces. Unlike whenHas()/
     * whenLoaded(), Laravel's whenAppended() invokes a Closure value with no arguments at all, so
     * only a non-first-class-callable EnumResource::make()/::collection() value is realistically
     * reachable here — still checked for consistency, since it costs nothing.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenAppended(MethodCall $call): array
    {
        $args = $call->getArgs();

        if ($args === [] || ! $args[0]->value instanceof String_) {
            return [...$this->unknownResult(), 'optional' => true]; // @codeCoverageIgnore
        }

        $info = $this->resolveModelAttributeTypeInfo($args[0]->value->value);
        $result = ['type' => $info['type'], 'optional' => false];

        if ($info['enumFqcn'] !== null) {
            $wrapped = count($args) >= 2 && $this->isEnumResourceWrapCall($args[1]->value);
            $result[$wrapped ? 'enumFqcn' : 'directEnumFqcn'] = $info['enumFqcn'];
        }

        return $this->applyConditionalDefault($result, $call, 2);
    }

    /**
     * Whether a whenHas()/whenAppended() value argument is EnumResource::make()/::collection() —
     * including the first-class-callable form — signalling the named attribute is EnumResource-
     * wrapped rather than read directly.
     */
    private function isEnumResourceWrapCall(Expr $value): bool
    {
        if (! $value instanceof StaticCall || ! $value->name instanceof Identifier) {
            return false;
        }

        $className = $this->resolveStaticCallClassName($value);

        return $className !== null
            && $this->isEnumResourceClass($className)
            && in_array($value->name->toString(), ['make', 'collection'], true);
    }

    /**
     * Analyze $this->whenExistsLoaded('relation', $value, $default) — resolves to the relation's
     * generated `{relation}_exists` flag.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenExistsLoaded(MethodCall $call): array
    {
        $args = $call->getArgs();

        if ($args === [] || ! $args[0]->value instanceof String_) {
            return [...$this->unknownResult(), 'optional' => true]; // @codeCoverageIgnore
        }

        return $this->applyConditionalDefault(['type' => 'boolean', 'optional' => false], $call, 2);
    }

    /**
     * Analyze $this->whenNotNull($value, $default) — the success arm returns $value, proven non-null.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenNotNull(MethodCall $call): array
    {
        return $this->analyzeWhenPossiblyNull($call, stripNull: true);
    }

    /**
     * Analyze $this->whenNull($value, $default) — the success arm returns null, so only the default
     * carries a useful type.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenNull(MethodCall $call): array
    {
        return $this->analyzeWhenPossiblyNull($call, stripNull: false);
    }

    /**
     * Shared logic for whenNotNull()/whenNull(): argument 0 is the value, argument 1 the optional
     * default. An explicit default makes the key required and unions its type into the result.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenPossiblyNull(MethodCall $call, bool $stripNull): array
    {
        $args = $call->getArgs();

        if ($args === []) {
            return [...$this->unknownResult(), 'optional' => true]; // @codeCoverageIgnore
        }

        $value = $this->analyzeValueExpression($args[0]->value);

        if ($stripNull) {
            $value['type'] = $this->stripNullArm($value['type']);
        } else {
            $value['type'] = 'null';
        }

        return $this->applyConditionalDefault($value, $call, 1);
    }

    /**
     * Analyze $this->whenLoaded('relation') or $this->whenLoaded('relation', value, default).
     *
     * A single-model relation's closure param binds to the model; a to-many relation's binds to the
     * collection type instead, since the param holds the whole collection rather than one element.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenLoaded(MethodCall $call): array
    {
        $result = $this->unknownResult();
        $args = $call->getArgs();

        if (count($args) >= 2) {
            // Resolve the related model so accesses on local variables inside the closure can be typed.
            $previousRelationModel = $this->scope->closureRelationModelClass;
            $previousVarModelBindings = $this->scope->varModelBindings;
            $previousVarCollectionBindings = $this->scope->varCollectionBindings;
            $relationInfo = null;

            if ($args[0]->value instanceof String_) {
                $relationInfo = $this->resolveModelRelationTypeInfo($args[0]->value->value);

                if ($relationInfo['modelFqcn'] !== null) {
                    $this->scope->closureRelationModelClass = $relationInfo['modelFqcn'];
                }
            }

            if ($relationInfo !== null
                && $relationInfo['modelFqcn'] !== null
                && ($args[1]->value instanceof ClosureExpr || $args[1]->value instanceof ArrowFunction)
                && isset($args[1]->value->params[0])
                && $args[1]->value->params[0]->var instanceof Variable
                && is_string($args[1]->value->params[0]->var->name)
            ) {
                $paramName = $args[1]->value->params[0]->var->name;

                if (str_ends_with($relationInfo['type'], '[]')) {
                    $this->scope->varCollectionBindings[$paramName] = [
                        'type' => $relationInfo['type'],
                        'modelFqcn' => $relationInfo['modelFqcn'],
                    ];
                } else {
                    $this->scope->varModelBindings[$paramName] = $relationInfo['modelFqcn'];
                }
            }

            try {
                $inner = $this->analyzeValueExpression($args[1]->value);
            } finally {
                $this->scope->closureRelationModelClass = $previousRelationModel;
                $this->scope->varModelBindings = $previousVarModelBindings;
                $this->scope->varCollectionBindings = $previousVarCollectionBindings;
            }

            return $this->applyConditionalDefault($inner, $call, 2);
        }

        if (count($args) >= 1 && $args[0]->value instanceof String_) {
            $relationName = $args[0]->value->value;
            $info = $this->resolveModelRelationTypeInfo($relationName);
            $result = ['type' => $info['type'], 'optional' => false];

            if ($info['modelFqcn'] !== null) {
                $result['modelFqcn'] = $info['modelFqcn'];
            }

            if ($info['morphFqcns'] !== []) {
                $result['embeddedModelFqcns'] = $info['morphFqcns'];
            }

            return $this->applyConditionalDefault($result, $call, 2);
        }

        return [...$result, 'optional' => true]; // @codeCoverageIgnore
    }

    /**
     * Analyze $this->transform($value, $callback, $default) — types from the callback's return, since
     * transform() invokes $callback with $value rather than passing $value through untouched.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeTransform(MethodCall $call): array
    {
        $result = $this->unknownResult();
        $args = $call->getArgs();

        if (count($args) >= 2) {
            $valueExpr = $args[0]->value;
            $callbackExpr = $args[1]->value;

            $previousBindings = $this->scope->closureParamExprBindings;
            $this->bindClosureParamsFromCondition($valueExpr, $callbackExpr);

            $inner = $this->analyzeValueExpression($callbackExpr);

            $this->scope->closureParamExprBindings = $previousBindings;

            // transform()'s default runs through the global transform() helper's $default($value) — one
            // argument — unlike the rest of the family's zero-argument value($default).
            return $this->applyConditionalDefault($inner, $call, 2, defaultArgCount: 1);
        }

        return [...$result, 'optional' => true]; // @codeCoverageIgnore
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
     * Analyze a static method call like EnumResource::make() or SomeResource::make/collection().
     *
     * @return ValueExpressionResult
     */
    protected function analyzeStaticCall(StaticCall $call): array
    {
        $result = $this->unknownResult();
        $className = $this->resolveStaticCallClassName($call);
        $methodName = $call->name instanceof Identifier ? $call->name->toString() : null;

        if ($className === null || $methodName === null) {
            return $result; // @codeCoverageIgnore
        }

        // Resolve `self`/`static` so those calls are treated identically to ClassName::*() calls.
        if ($className === 'self' || $className === 'static') {
            $className = $this->scope->subjectReflection->getName();
        }

        // EnumResource::make($this->prop)
        if ($this->isEnumResourceClass($className) && $methodName === 'make') {
            return $this->analyzeEnumResourceMake($call);
        }

        // EnumResource::collection($this->prop) — must precede the generic isResourceClass()
        // checks below: EnumResource extends JsonResource, so those would match it too and
        // yield the unsuffixed 'EnumResource[]' instead of resolving the wrapped enum.
        if ($this->isEnumResourceClass($className) && $methodName === 'collection') {
            return $this->analyzeEnumResourceCollection($call);
        }

        // SomeCollection::make()/::collection() on a ResourceCollection subclass. Must precede the generic
        // checks below: ResourceCollection extends JsonResource, so isResourceClass() matches it too and
        // would yield the unsuffixed collection name instead of 'OrderItemResource[]'.
        if (is_a($className, ResourceCollection::class, true) && in_array($methodName, ['make', 'collection'], true)) {
            $collected = $this->collectedResourceClass($className);

            if ($collected !== null) {
                return [
                    ...$result,
                    'type' => $this->wrapCollectionElementType(LaravelTsPublish::resourceTypeName($collected), new ReflectionClass($className)),
                    'optional' => $this->hasConditionalArgument($call),
                    'resourceFqcn' => $collected,
                ];
            }
        }

        // SomeResource::make($this->prop) — nested resource
        if ($this->isResourceClass($className) && $methodName === 'make') {
            $resourceName = LaravelTsPublish::resourceTypeName($className);
            $optional = $this->hasConditionalArgument($call);

            /** @var class-string $className */
            return [
                ...$result,
                'type' => $resourceName,
                'optional' => $optional,
                'resourceFqcn' => $className,
            ];
        }

        // SomeResource::collection(...) — array or keyed record of nested resource
        if ($this->isResourceClass($className) && $methodName === 'collection') {
            $resourceName = LaravelTsPublish::resourceTypeName($className);
            $optional = $this->hasConditionalArgument($call);

            /** @var class-string $className */
            return [
                ...$result,
                'type' => $this->wrapCollectionElementType($resourceName, new ReflectionClass($className)),
                'optional' => $optional,
                'resourceFqcn' => $className,
            ];
        }

        // Any other existing class — reflect the static method's return type. Accepted only when it
        // cannot break generated imports; see acceptReflectedTypeInfo().
        if (class_exists($className)) {
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($className), $methodName);

            return $this->acceptReflectedTypeInfo($tsInfo) ?? $result;
        }

        return $result;
    }

    /**
     * Resolve a fluent method chained onto a receiver that itself resolves to a resource — e.g.
     * `new self($x)->foo()`, `SomeResource::make($x)->foo()`, or a chain of such calls. The
     * receiver's own resolved result is returned unchanged when the method preserves it; otherwise
     * the method's own body is resolved, and an unreflectable receiver yields null to degrade.
     *
     * @return ValueExpressionResult|null
     */
    protected function analyzeSelfReturningResourceMethodCall(MethodCall $expr): ?array
    {
        if (! $expr->name instanceof Identifier) {
            return null; // @codeCoverageIgnore
        }

        $receiverResult = $this->analyzeValueExpression($expr->var);
        $resourceFqcn = $receiverResult['resourceFqcn'] ?? null;

        // A collection receiver (e.g. ::collection()) resolves to an AnonymousResourceCollection
        // instance, not a $resourceFqcn instance — reflecting the method below would validate
        // against the wrong receiver, so exclude it rather than misfire on e.g. ->additional().
        if ($resourceFqcn === null || $receiverResult['type'] !== LaravelTsPublish::resourceTypeName($resourceFqcn)) {
            return null;
        }

        $methodName = $expr->name->toString();

        if (! method_exists($resourceFqcn, $methodName)) {
            return null;
        }

        $method = new ReflectionMethod($resourceFqcn, $methodName);

        // Not self-returning: the expression is the method's payload, not the resource. Resolving it
        // needs the receiver's own analyzer, so only the analyzer's own class is in scope — a foreign
        // resource class returns null and keeps the `unknown` floor rather than claiming its keys.
        if (! $this->methodPreservesReceiverType($method, $resourceFqcn)) {
            if ($resourceFqcn !== $this->scope->subjectReflection->getName()) {
                return null;
            }

            $analysis = $this->analyzeThisMethodSpread($methodName);

            if ($analysis === null || $analysis->properties === []) {
                return null;
            }

            return ['type' => $this->buildInlineObjectType($analysis), 'optional' => false];
        }

        if ($this->methodReturnAllowsNull($method) && ! str_contains($receiverResult['type'], 'null')) {
            $receiverResult['type'] .= ' | null';
        }

        return $receiverResult;
    }

    /**
     * Whether a method's declared return type says it hands the same instance back — a native
     * `static`, `self`, or the resource class itself; falling back to a `@return $this` docblock
     * only when no native return type is declared at all. A union or intersection return type is
     * rejected outright and never falls through to the docblock.
     */
    protected function methodPreservesReceiverType(ReflectionMethod $method, string $resourceFqcn): bool
    {
        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType) {
            $name = $returnType->getName();

            return $name === 'static' || $name === 'self' || $name === $resourceFqcn;
        }

        if ($returnType !== null) {
            return false;
        }

        $docComment = $method->getDocComment();

        if ($docComment === false) {
            return false;
        }

        // extractReturnTypeFromDocblock()'s final fallback is `\S+`, so the token it returns
        // can never carry surrounding whitespace — no trim() needed before comparing.
        return LaravelTsPublish::extractReturnTypeFromDocblock($docComment) === '$this';
    }

    /**
     * Whether a self-returning method's native return type also allows null (`?static`). The
     * docblock-only `@return $this` fallback carries no nullability signal, so this only
     * inspects a `ReflectionNamedType` — the same shape methodPreservesReceiverType() required
     * to have already matched before this is ever called.
     */
    protected function methodReturnAllowsNull(ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();

        return $returnType instanceof ReflectionNamedType && $returnType->allowsNull();
    }

    /**
     * Resolve the resource class a ResourceCollection collects, from the #[Collects] attribute, the
     * $collects property default, or the FooCollection → FooResource naming convention.
     *
     * @param  class-string  $collectionFqcn
     * @return class-string<JsonResource>|null
     */
    protected function collectedResourceClass(string $collectionFqcn): ?string
    {
        return $this->resolveCollectedResourceClass($collectionFqcn);
    }

    /**
     * Analyze `$model->toResource()` / `$model->toResource(SomeResource::class)`. An explicit
     * argument wins outright; otherwise the receiver's model resolves via resolveResourceForModel().
     *
     * @return ValueExpressionResult
     */
    protected function analyzeToResourceCall(MethodCall $call): array
    {
        $result = $this->unknownResult();
        $args = $call->getArgs();

        if ($args !== []) {
            $explicit = $this->resolveClassConstArgument($args[0]->value);

            if ($explicit === null || ! $this->isResourceClass($explicit)) {
                return $result;
            }

            /** @var class-string $explicit */
            return [...$result, 'type' => LaravelTsPublish::resourceTypeName($explicit), 'optional' => false, 'resourceFqcn' => $explicit];
        }

        $modelFqcn = $this->resolveToResourceReceiverModel($call->var);
        $resourceFqcn = $modelFqcn !== null ? $this->resolveResourceForModel($modelFqcn) : null;

        if ($resourceFqcn === null) {
            return $result;
        }

        return [...$result, 'type' => LaravelTsPublish::resourceTypeName($resourceFqcn), 'optional' => false, 'resourceFqcn' => $resourceFqcn];
    }

    /**
     * Analyze `$collection->toResourceCollection()` / `->toResourceCollection(SomeResource::class)`.
     * An explicit argument wins outright; otherwise the receiver's model resolves via
     * resolveResourceCollectionForModel().
     *
     * @return ValueExpressionResult
     */
    protected function analyzeToResourceCollectionCall(MethodCall $call): array
    {
        $result = $this->unknownResult();
        $args = $call->getArgs();

        if ($args !== []) {
            $explicit = $this->resolveClassConstArgument($args[0]->value);

            if ($explicit === null || ! $this->isResourceClass($explicit)) {
                return $result;
            }

            /** @var class-string $explicit */
            return [
                ...$result,
                'type' => $this->wrapCollectionElementType(LaravelTsPublish::resourceTypeName($explicit), new ReflectionClass($explicit)),
                'optional' => false,
                'resourceFqcn' => $explicit,
            ];
        }

        $modelFqcn = $this->resolveToResourceReceiverModel($call->var);
        $resolved = $modelFqcn !== null ? $this->resolveResourceCollectionForModel($modelFqcn) : null;

        if ($resolved === null) {
            return $result;
        }

        return [
            ...$result,
            'type' => $this->wrapCollectionElementType(
                LaravelTsPublish::resourceTypeName($resolved['resourceFqcn']),
                new ReflectionClass($resolved['collectionFqcn']),
            ),
            'optional' => false,
            'resourceFqcn' => $resolved['resourceFqcn'],
        ];
    }

    /**
     * Resolve the model class backing a toResource()/toResourceCollection() receiver: a whenLoaded
     * closure parameter (analyzeWhenLoaded()'s bindings) or `$this->relation` accessed directly.
     *
     * @return class-string<Model>|null
     */
    protected function resolveToResourceReceiverModel(Expr $receiver): ?string
    {
        if ($receiver instanceof Variable && is_string($receiver->name)) {
            return $this->scope->varModelBindings[$receiver->name]
                ?? $this->scope->varCollectionBindings[$receiver->name]['modelFqcn']
                ?? $this->scope->closureRelationModelClass;
        }

        if ($receiver instanceof PropertyFetch && $this->isThisPropertyFetch($receiver) && $receiver->name instanceof Identifier) {
            return $this->resolveModelRelationTypeInfo($receiver->name->toString())['modelFqcn'];
        }

        return null;
    }

    /**
     * Resolve a `SomeClass::class` argument node to its FQCN.
     */
    protected function resolveClassConstArgument(Expr $expr): ?string
    {
        if ($expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            return $expr->class->toString();
        }

        return null;
    }

    /**
     * Resolve `SomeClass::CONSTANT` as a value expression. Reads the constant via reflection and
     * feeds its PHP value back through analyzeConstantValue(), reusing analyzeValueExpression()'s
     * scalar dispatch for leaves. Returns null for anything not a resolvable plain constant.
     *
     * @return ValueExpressionResult|null
     */
    protected function resolveClassConstantValueExpression(ClassConstFetch $expr): ?array
    {
        if (! $expr->class instanceof Name || ! $expr->name instanceof Identifier) {
            return null; // @codeCoverageIgnore
        }

        $constName = $expr->name->toString();

        // `Foo::class`/`Foo::CLASS` (the keyword is case-insensitive) is a compile-time magic
        // constant, not a real declared one — reflection can't read it. It is a string at runtime.
        if (strtolower($constName) === 'class') {
            return ['type' => 'string', 'optional' => false];
        }

        $className = $expr->class->toString();

        // Resolve self/static/parent so a constant declared on the resource (or its parent) is
        // readable, matching how analyzeNewResource()/analyzeStaticCall() treat those keywords.
        if ($className === 'self' || $className === 'static') {
            $className = $this->scope->subjectReflection->getName();
        } elseif ($className === 'parent') {
            $parentReflection = $this->scope->subjectReflection->getParentClass();

            if ($parentReflection === false) {
                return null; // @codeCoverageIgnore — every JsonResource subclass has a parent
            }

            $className = $parentReflection->getName();
        }

        if (! class_exists($className) && ! interface_exists($className) && ! enum_exists($className)) {
            return null;
        }

        $classReflection = new ReflectionClass($className);

        if (! $classReflection->hasConstant($constName)) {
            return null;
        }

        $constantReflection = $classReflection->getReflectionConstant($constName);

        // Enum cases resolve through resolveEnumFromPropertyArg()'s dedicated branch instead
        // (EnumResource::make(Status::Active) etc.) — a bare case fetch here must not be
        // reinterpreted as a plain constant's literal value.
        if ($constantReflection === false || $constantReflection->isEnumCase()) {
            return null;
        }

        try {
            $value = $constantReflection->getValue();
        } catch (Throwable) {
            // The initializer can reference another undefined constant; PHP evaluates a class
            // constant's value lazily, so that only surfaces here, not at class-load time.
            return null;
        }

        return $this->analyzeConstantValue($value);
    }

    /**
     * Convert a reflected constant's PHP value into a TS type, recursing into arrays. A scalar
     * reuses analyzeValueExpression()'s existing dispatch via a synthetic AST node instead of a
     * parallel value-to-TS mapper; a constant typed as another enum's case resolves to that enum.
     *
     * @return ValueExpressionResult|null
     */
    protected function analyzeConstantValue(mixed $value): ?array
    {
        if (is_array($value)) {
            return $this->analyzeConstantArrayValue($value);
        }

        // A constant's initializer may itself be another class's enum case (`Status::Live`),
        // which getValue() hands back as the enum instance rather than a scalar.
        if ($value instanceof UnitEnum) {
            $enumFqcn = $value::class;

            return [
                'type' => LaravelTsPublish::toTsType($enumFqcn)['type'],
                'optional' => false,
                'directEnumFqcn' => $enumFqcn,
            ];
        }

        // Defensive: a class-constant expression can't construct an arbitrary object (`new` isn't
        // allowed there), so only an enum instance — handled above — reaches this as non-scalar.
        if (! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null; // @codeCoverageIgnore
        }

        return $this->analyzeValueExpression(new BuilderFactory()->val($value));
    }

    /**
     * Convert a reflected constant's array value into a TS shape: empty stays `never[]`, a keyed
     * array becomes an inline object, a plain list becomes an element array, and either bails to
     * unknown when the array exceeds constantArrayWithinLimits().
     *
     * @param  array<array-key, mixed>  $value
     * @return ValueExpressionResult|null
     */
    protected function analyzeConstantArrayValue(array $value): ?array
    {
        if ($value === []) {
            return ['type' => 'never[]', 'optional' => false];
        }

        if (! $this->constantArrayWithinLimits($value)) {
            return null;
        }

        return array_is_list($value)
            ? $this->analyzeConstantListValue($value)
            : $this->analyzeConstantRecordValue($value);
    }

    /**
     * Convert a plain-list constant array into an element type: `T[]` when every element agrees,
     * `(A | B)[]` when they don't, or null (unknown) when any element can't itself be resolved.
     *
     * Recurses through analyzeConstantValue() — rather than delegating the whole array back to the
     * AST pipeline — so a list nested inside a keyed constant (analyzeConstantRecordValue()) is
     * resolved the same way a top-level one is: analyzeReturnArray() has no key to shape a keyless
     * item from and would otherwise silently drop every element.
     *
     * @param  list<mixed>  $value
     * @return ValueExpressionResult|null
     */
    protected function analyzeConstantListValue(array $value): ?array
    {
        $types = [];
        $embeddedEnumFqcns = [];

        foreach ($value as $item) {
            $itemResult = $this->analyzeConstantValue($item);

            if ($itemResult === null || $itemResult['type'] === 'unknown') {
                return null;
            }

            $types[] = $itemResult['type'];
            $embeddedEnumFqcns = [...$embeddedEnumFqcns, ...$this->collectConstantEnumFqcns($itemResult)];
        }

        $types = array_values(array_unique($types));
        $elementType = count($types) === 1 ? $types[0] : '('.implode(' | ', $types).')';

        $result = ['type' => $elementType.'[]', 'optional' => false];

        if ($embeddedEnumFqcns !== []) {
            $result['embeddedEnumFqcns'] = array_values(array_unique($embeddedEnumFqcns));
        }

        return $result;
    }

    /**
     * Convert a keyed constant array into an inline object, formatted the same way
     * analyzeInlineArray() builds one. A member that can't itself be resolved types as `unknown`
     * rather than failing the whole shape, matching analyzeReturnArray()'s per-property behaviour.
     *
     * An int-keyed member (not routed to analyzeConstantListValue(), since the array as a whole
     * isn't a list — e.g. `[200 => 'OK', 404 => 'Not Found']`) is dropped, matching how
     * resolveKeyName() already treats a non-string AST array key everywhere else in this class.
     *
     * @param  array<array-key, mixed>  $value
     * @return ValueExpressionResult
     */
    protected function analyzeConstantRecordValue(array $value): array
    {
        $parts = [];
        $embeddedEnumFqcns = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $itemResult = $this->analyzeConstantValue($item) ?? $this->unknownResult();
            $formattedKey = LaravelTsPublish::validJsObjectKey($key);
            $parts[] = "{$formattedKey}: {$itemResult['type']}";
            $embeddedEnumFqcns = [...$embeddedEnumFqcns, ...$this->collectConstantEnumFqcns($itemResult)];
        }

        if ($parts === []) {
            return ['type' => 'Record<string, unknown>', 'optional' => false];
        }

        $result = ['type' => '{ '.implode('; ', $parts).' }', 'optional' => false];

        if ($embeddedEnumFqcns !== []) {
            $result['embeddedEnumFqcns'] = array_values(array_unique($embeddedEnumFqcns));
        }

        return $result;
    }

    /**
     * Gather the enum FQCNs a resolved constant element carries — its own directEnumFqcn (a bare
     * enum-case leaf) plus any already-embedded ones (a nested list/record containing one) — so
     * analyzeConstantListValue()/analyzeConstantRecordValue() can propagate them to their caller via
     * the same embeddedEnumFqcns channel analyzeInlineArray() uses to make the import land.
     *
     * @param  ValueExpressionResult  $itemResult
     * @return list<class-string>
     */
    protected function collectConstantEnumFqcns(array $itemResult): array
    {
        /** @var list<class-string> $fqcns */
        $fqcns = $itemResult['embeddedEnumFqcns'] ?? [];

        if (isset($itemResult['directEnumFqcn'])) {
            $fqcns[] = $itemResult['directEnumFqcn'];
        }

        return $fqcns;
    }

    /**
     * Guard a class-constant array against inlining an unreadable type: too many total elements
     * or nested too deep. Both limits are generous for realistic config-shaped constants (the
     * eaglesys OWNER_MINIMUM_CHANNELS shape is 2 levels deep with about a dozen elements) while
     * blocking a large external lookup table from bloating every resource that references it.
     *
     * @param  array<array-key, mixed>  $value
     */
    protected function constantArrayWithinLimits(array $value): bool
    {
        if (count($value, COUNT_RECURSIVE) > self::MAX_CONSTANT_ARRAY_ELEMENTS) {
            return false;
        }

        return $this->constantArrayDepth($value) <= self::MAX_CONSTANT_ARRAY_DEPTH;
    }

    /**
     * Compute the deepest nesting level of an array, counting the array itself as depth 1.
     *
     * @param  array<array-key, mixed>  $value
     */
    protected function constantArrayDepth(array $value, int $depth = 1): int
    {
        $deepest = $depth;

        foreach ($value as $item) {
            if (is_array($item)) {
                $deepest = max($deepest, $this->constantArrayDepth($item, $depth + 1));
            }
        }

        return $deepest;
    }

    /**
     * Reproduce Model::toResource()'s guessResource(): the #[UseResource] attribute first, then
     * the naming-convention candidates, Resource-suffixed candidate first.
     *
     * @param  class-string<Model>  $modelFqcn
     * @return class-string|null
     */
    protected function resolveResourceForModel(string $modelFqcn): ?string
    {
        $fromAttribute = $this->resolveUseResourceAttribute($modelFqcn);

        if ($fromAttribute !== null) {
            return $fromAttribute;
        }

        foreach ($this->guessResourceNames($modelFqcn) as $candidate) {
            if ($this->isPublishedResourceClass($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Reproduce Collection::toResourceCollection()'s guessResourceCollection() order: the
     * #[UseResourceCollection] attribute, then #[UseResource], then the naming convention —
     * trying `{Guessed}Collection` classes before the bare guessed resources.
     *
     * @param  class-string<Model>  $modelFqcn
     * @return array{collectionFqcn: class-string, resourceFqcn: class-string}|null
     */
    protected function resolveResourceCollectionForModel(string $modelFqcn): ?array
    {
        // Vendor returns `new $useResourceCollection($this)` unconditionally once the attribute
        // names an existing class — it never falls through to #[UseResource] or the naming
        // convention, even when the element type can't be determined here. Match that: stop hard.
        $collectionFqcn = $this->resolveUseResourceCollectionAttribute($modelFqcn);

        if ($collectionFqcn !== null) {
            $resourceFqcn = $this->collectedResourceClass($collectionFqcn);

            return $resourceFqcn !== null
                ? ['collectionFqcn' => $collectionFqcn, 'resourceFqcn' => $resourceFqcn]
                : null;
        }

        $resourceFqcn = $this->resolveUseResourceAttribute($modelFqcn);

        if ($resourceFqcn !== null) {
            return ['collectionFqcn' => $resourceFqcn, 'resourceFqcn' => $resourceFqcn];
        }

        $candidates = $this->guessResourceNames($modelFqcn);

        // Same shape here: vendor's own loop returns `new $resourceCollection($this)` the moment
        // `class_exists($resourceCollection)` passes for a candidate, never trying the next one.
        foreach ($candidates as $candidate) {
            $collectionCandidate = $candidate.'Collection';

            if (class_exists($collectionCandidate)
                && is_a($collectionCandidate, ResourceCollection::class, true)
                && PublishedResourceRegistry::isPublished($collectionCandidate)
            ) {
                $collectedFqcn = $this->collectedResourceClass($collectionCandidate);

                return $collectedFqcn !== null
                    ? ['collectionFqcn' => $collectionCandidate, 'resourceFqcn' => $collectedFqcn]
                    : null;
            }
        }

        foreach ($candidates as $candidate) {
            if ($this->isPublishedResourceClass($candidate)) {
                return ['collectionFqcn' => $candidate, 'resourceFqcn' => $candidate];
            }
        }

        return null;
    }

    /**
     * Reproduce Model::guessResourceName()'s `\Models\` to `\Http\Resources\` naming convention.
     *
     * @param  class-string<Model>  $modelFqcn
     * @return list<class-string>
     */
    protected function guessResourceNames(string $modelFqcn): array
    {
        if (! str_contains($modelFqcn, '\\Models\\')) {
            return [];
        }

        $basename = class_basename($modelFqcn);
        $relativeNamespace = Str::after($modelFqcn, '\\Models\\');

        $relativeNamespace = str_contains($relativeNamespace, '\\')
            ? Str::beforeLast($relativeNamespace, '\\'.$basename)
            : '';

        $potentialResource = sprintf(
            '%s\\Http\\Resources\\%s%s',
            Str::before($modelFqcn, '\\Models'),
            $relativeNamespace !== '' ? $relativeNamespace.'\\' : '',
            $basename,
        );

        /** @var list<class-string> */
        return [$potentialResource.'Resource', $potentialResource];
    }

    /**
     * Read the #[UseResource] attribute directly off a model class.
     *
     * @param  class-string<Model>  $modelFqcn
     * @return class-string|null
     */
    protected function resolveUseResourceAttribute(string $modelFqcn): ?string
    {
        $attributeFqcn = 'Illuminate\Database\Eloquent\Attributes\UseResource';

        if (! class_exists($attributeFqcn) || ! class_exists($modelFqcn)) {
            return null;
        }

        $attributes = new ReflectionClass($modelFqcn)->getAttributes($attributeFqcn);

        if ($attributes === []) {
            return null;
        }

        $resourceFqcn = $attributes[0]->newInstance()->class;

        return $this->isResourceClass($resourceFqcn) ? $resourceFqcn : null;
    }

    /**
     * Read the #[UseResourceCollection] attribute directly off a model class.
     *
     * @param  class-string<Model>  $modelFqcn
     * @return class-string|null
     */
    protected function resolveUseResourceCollectionAttribute(string $modelFqcn): ?string
    {
        $attributeFqcn = 'Illuminate\Database\Eloquent\Attributes\UseResourceCollection';

        if (! class_exists($attributeFqcn) || ! class_exists($modelFqcn)) {
            return null;
        }

        $attributes = new ReflectionClass($modelFqcn)->getAttributes($attributeFqcn);

        if ($attributes === []) {
            return null;
        }

        $collectionFqcn = $attributes[0]->newInstance()->class;

        return class_exists($collectionFqcn) && is_a($collectionFqcn, ResourceCollection::class, true)
            ? $collectionFqcn
            : null;
    }

    /**
     * Accept a reflected TypeScriptTypeInfo as a ValueExpressionResult, or null when any referenced
     * type can't be imported.
     *
     * A non-Model class token has no published file to import, so its presence rejects the whole result.
     *
     * @param  TypeScriptTypeInfo  $tsInfo
     * @return ValueExpressionResult|null
     */
    protected function acceptReflectedTypeInfo(array $tsInfo): ?array
    {
        if (in_array($tsInfo['type'], ['unknown', 'unknown | null', 'void', 'never', ''], true)) {
            return null;
        }

        foreach ($tsInfo['classFqcns'] as $fqcn) {
            if (! is_a($fqcn, Model::class, true)) {
                return null;
            }
        }

        $result = [...$this->unknownResult(), 'type' => $tsInfo['type'], 'optional' => false];

        if (count($tsInfo['enumFqcns']) === 1 && $tsInfo['classFqcns'] === []) {
            $result['directEnumFqcn'] = $tsInfo['enumFqcns'][0];
        } elseif ($tsInfo['enumFqcns'] !== []) {
            $result['embeddedEnumFqcns'] = $tsInfo['enumFqcns'];
        }

        if (count($tsInfo['classFqcns']) === 1 && $tsInfo['enumFqcns'] === []) {
            /** @var class-string<Model> $modelFqcn */
            $modelFqcn = $tsInfo['classFqcns'][0];
            $result['modelFqcn'] = $modelFqcn;
        } elseif ($tsInfo['classFqcns'] !== []) {
            $result['embeddedModelFqcns'] = $tsInfo['classFqcns'];
        }

        if ($tsInfo['customImports'] !== []) {
            $result['customImports'] = $tsInfo['customImports'];
        }

        return $result;
    }

    /**
     * Analyze `new SomeResource(...)` — resolve as a nested resource.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeNewResource(New_ $expr): array
    {
        $result = $this->unknownResult();

        if (! $expr->class instanceof Name) {
            return $result; // @codeCoverageIgnore
        }

        $className = $expr->class->toString();

        // Resolve `self`/`static` so `new self(...)` is treated identically to `new ClassName(...)`.
        if ($className === 'self' || $className === 'static') {
            $className = $this->scope->subjectReflection->getName();
        }

        // new EnumResource($this->prop)
        if ($this->isEnumResourceClass($className)) {
            $args = $expr->getArgs();

            if (count($args) >= 1) {
                return $this->resolveEnumFromPropertyArg($args[0]->value) ?? $result;
            }

            return $result;
        }

        // new SomeCollection($this->items) — resolve the collected element type. Must precede the
        // generic isResourceClass() branch below, for the same reason as in analyzeStaticCall().
        if (is_a($className, ResourceCollection::class, true)) {
            $collected = $this->collectedResourceClass($className);

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
     * Analyze EnumResource::make($this->prop) — resolve the enum class from the model property.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeEnumResourceMake(StaticCall $call): array
    {
        $result = $this->unknownResult();

        if ($call->isFirstClassCallable()) {
            return $result;
        }

        $args = $call->getArgs();

        if (count($args) < 1) {
            return $result;
        }

        return $this->resolveEnumFromPropertyArg($args[0]->value) ?? $result;
    }

    /**
     * Analyze EnumResource::collection($this->prop) — resolve the enum class and array-wrap it.
     *
     * A first-class callable carries no argument at the call site to resolve the enum from — the
     * value is supplied later by whichever conditional method invokes it — so it degrades to
     * unknown rather than guessing, matching analyzeEnumResourceMake()'s FCC bail-out.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeEnumResourceCollection(StaticCall $call): array
    {
        $result = $this->unknownResult();

        if ($call->isFirstClassCallable()) {
            return $result;
        }

        $args = $call->getArgs();

        if (count($args) < 1) {
            return $result;
        }

        $enumResult = $this->resolveEnumFromPropertyArg($args[0]->value);

        if ($enumResult === null) {
            return $result;
        }

        // The resolved property may already be a collection type (an AsEnumCollection cast or a
        // list<Enum> accessor both resolve their own '[]' already) — only wrap when it isn't.
        $type = $enumResult['type'];
        $alreadyCollection = str_ends_with(rtrim(str_replace('| null', '', $type)), '[]');

        return [
            ...$enumResult,
            'type' => $alreadyCollection ? $type : $this->arrayWrapType($type),
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
    protected function resolveEnumFromPropertyArg(Expr $argExpr): ?array
    {
        $result = $this->unknownResult();

        if (! $this->isThisPropertyFetch($argExpr)) {
            // A bare $variable may be a closure parameter bound to $this->prop by a when() condition.
            if ($argExpr instanceof Variable && is_string($argExpr->name)) {
                $boundExpr = $this->scope->closureParamExprBindings[$argExpr->name] ?? null;

                if ($boundExpr !== null) {
                    return $this->resolveEnumFromPropertyArg($boundExpr);
                }
            }

            // Handle $variable->property inside a whenLoaded closure.
            if (
                $argExpr instanceof PropertyFetch
                && $argExpr->var instanceof Variable
                && $argExpr->name instanceof Identifier
                && $this->scope->closureRelationModelClass !== null
            ) {
                $propName = $argExpr->name->toString();
                $tsInfo = resolve(ModelAttributeResolver::class)->resolveAttribute($this->scope->closureRelationModelClass, $propName);

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
                $info = $this->resolveModelAttributeTypeInfo($propName);

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

        $info = $this->resolveModelAttributeTypeInfo($propName);

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
     * Resolve an arrow function's or closure's return type annotation to a ClosureAnnotationResult.
     * Returns null when the annotation is absent, is a union/intersection, or maps to void/mixed/never
     * or an unresolvable class.
     *
     * @return ClosureAnnotationResult|null
     */
    private function resolveClosureAstReturnType(Expr $expr): ?array
    {
        if (! $expr instanceof ArrowFunction && ! $expr instanceof ClosureExpr) {
            return null;
        }

        $returnType = $expr->returnType;

        if ($returnType === null) {
            return null;
        }

        return $this->convertAstTypeNodeToTs($returnType);
    }

    /**
     * Convert a PHP-Parser return-type AST node to a ClosureAnnotationResult (type + optional FQCN).
     *
     * Returns null for union/intersection types, void/never/mixed, unresolvable classes, and types
     * carrying customImports — that import metadata cannot be represented in ValueExpressionResult.
     *
     * @return ClosureAnnotationResult|null
     */
    private function convertAstTypeNodeToTs(Node $typeNode): ?array
    {
        if ($typeNode instanceof NullableType) {
            $inner = $this->convertAstTypeNodeToTs($typeNode->type);

            if ($inner === null) {
                return null;
            }

            return [...$inner, 'type' => $inner['type'].' | null'];
        }

        if ($typeNode instanceof Identifier) {
            $phpType = $typeNode->toString();

            if (in_array($phpType, ['void', 'never', 'mixed'], true)) {
                return null;
            }

            $tsInfo = LaravelTsPublish::toTsType($phpType);

            return $tsInfo['type'] !== 'unknown' ? ['type' => $tsInfo['type']] : null;
        }

        if ($typeNode instanceof Name) {
            $phpType = $typeNode->toString();
            $tsInfo = LaravelTsPublish::toTsType($phpType);

            if ($tsInfo['type'] === 'unknown') {
                return null;
            }

            if ($tsInfo['customImports'] !== []) {
                return null;
            }

            /** @var ClosureAnnotationResult $result */
            $result = ['type' => $tsInfo['type']];

            if ($tsInfo['enumFqcns'] !== []) {
                $result['directEnumFqcn'] = $tsInfo['enumFqcns'][0];
            } elseif ($tsInfo['classFqcns'] !== []) {
                $result['modelFqcn'] = $tsInfo['classFqcns'][0];
            }

            return $result;
        }

        // UnionType / IntersectionType — fall through to body analysis
        return null;
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
     * See collectedResourceClass() for the resolution order.
     *
     * @return class-string<JsonResource>|null
     */
    protected function resolveSingularResourceClass(): ?string
    {
        /** @var class-string $ownFqcn */
        $ownFqcn = $this->scope->subjectReflection->getName();

        return $this->collectedResourceClass($ownFqcn);
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
            $accepted = $this->acceptReflectedTypeInfo($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        } elseif ($this->scope->modelClass !== null && method_exists($this->scope->modelClass, $methodName)) {
            // @mixin-style resources: `$this->resource->commentsCount()` lives on the model.
            /** @var class-string $modelClass */
            $modelClass = $this->scope->modelClass;
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($modelClass), $methodName);
            $accepted = $this->acceptReflectedTypeInfo($tsInfo);

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

                    $accepted = $this->acceptReflectedTypeInfo($tsInfo);

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
     * Drop a top-level `| null` arm from a type string — a guarded success path proves it unreachable.
     * Nested null members (inside object shapes, generics, or array element types) are kept.
     */
    private function stripNullArm(string $type): string
    {
        $members = array_values(array_filter(
            LaravelTsPublish::splitTopLevelUnion($type),
            fn (string $member): bool => $member !== 'null',
        ));

        return $members === [] ? 'unknown' : implode(' | ', $members);
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
     * Whether an explicit default was passed at the given argument index. Laravel distinguishes a
     * passed-through `null` from an omitted argument via func_num_args(), so position is the only
     * signal; named or spread arguments make the position meaningless, so both bail out.
     */
    private function hasExplicitDefaultArg(MethodCall $call, int $index): bool
    {
        foreach ($call->getArgs() as $arg) {
            if ($arg->unpack || $arg->name !== null) {
                return false;
            }
        }

        return count($call->getArgs()) > $index;
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
     * Analyze a `$this->resource::staticMethod()` call against the wrapped class, then the @mixin model.
     *
     * Each reflection is accepted only when its tokens can be imported; see acceptReflectedTypeInfo().
     *
     * @return ValueExpressionResult
     */
    protected function analyzeStaticMethodOnResource(string $methodName): array
    {
        $result = $this->unknownResult();
        $wrappedClass = $this->resolveWrappedClass();

        if ($wrappedClass !== null && method_exists($wrappedClass, $methodName)) {
            /** @var class-string $wrappedClass */
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($wrappedClass), $methodName);
            $accepted = $this->acceptReflectedTypeInfo($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        }

        if ($this->scope->modelClass !== null && method_exists($this->scope->modelClass, $methodName)) {
            /** @var class-string $modelClass */
            $modelClass = $this->scope->modelClass;
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($modelClass), $methodName);
            $accepted = $this->acceptReflectedTypeInfo($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        }

        return $result;
    }

    /**
     * Resolve a property access on a related model — an explicitly bound model, or by default the
     * ambient whenLoaded closure's related model.
     *
     * Uses the same resolution chain as model attributes: accessor → cast → DB column type.
     *
     * @param  class-string<Model>|null  $modelFqcn
     * @return ValueExpressionResult
     */
    protected function analyzeRelatedModelProperty(string $propertyName, ?string $modelFqcn = null): array
    {
        $modelFqcn ??= $this->scope->closureRelationModelClass;

        if ($modelFqcn === null) {
            return $this->unknownResult(); // @codeCoverageIgnore
        }

        $tsInfo = resolve(ModelAttributeResolver::class)->resolveAttribute($modelFqcn, $propertyName);

        if ($tsInfo['type'] === 'unknown') {
            return $this->unknownResult();
        }

        $info = ['type' => $tsInfo['type'], 'optional' => false];

        /** @var class-string|null $enumFqcn */
        $enumFqcn = $tsInfo['enumFqcns'][0] ?? null;

        if ($enumFqcn !== null) {
            $info['directEnumFqcn'] = $enumFqcn;
        }

        return $info;
    }

    /**
     * Resolve a method call (instance or static) on a related model — an explicitly bound model, or
     * by default the ambient whenLoaded closure's related model.
     *
     * Accepted only when its tokens can be imported; see acceptReflectedTypeInfo().
     *
     * @param  class-string<Model>|null  $modelFqcn
     * @return ValueExpressionResult
     */
    protected function analyzeRelatedModelMethodCall(string $methodName, ?string $modelFqcn = null): array
    {
        $modelFqcn ??= $this->scope->closureRelationModelClass;

        if ($modelFqcn === null) {
            return $this->unknownResult(); // @codeCoverageIgnore
        }

        $tsInfo = resolve(ModelAttributeResolver::class)->resolveMethodReturnType($modelFqcn, $methodName);

        return $this->acceptReflectedTypeInfo($tsInfo) ?? $this->unknownResult();
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
     * Analyze a generic `$this->method()` by reflecting its declared return type.
     *
     * Checks own methods, then the wrapped class, then the backing model, to cover calls delegated
     * via `__call`/`@mixin`.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeThisMethodCall(string $methodName): array
    {
        if ($this->scope->subjectReflection->hasMethod($methodName)) {
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes($this->scope->subjectReflection, $methodName);
            $accepted = $this->acceptReflectedTypeInfo($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        }

        $wrappedClass = $this->resolveWrappedClass();

        if ($wrappedClass !== null && method_exists($wrappedClass, $methodName)) {
            /** @var class-string $wrappedClass */
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($wrappedClass), $methodName);
            $accepted = $this->acceptReflectedTypeInfo($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        }

        if ($this->scope->modelClass !== null && method_exists($this->scope->modelClass, $methodName)) {
            /** @var class-string $modelClass */
            $modelClass = $this->scope->modelClass;
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($modelClass), $methodName);
            $accepted = $this->acceptReflectedTypeInfo($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        }

        return $this->unknownResult();
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
     * Merge multiple closure return expressions into a single union-typed ValueExpressionResult.
     *
     * Null returns (guard clauses) contribute `null` to the union instead of a full object shape;
     * duplicate types are removed and import metadata is collected from all branches.
     *
     * @param  list<Expr>  $returns
     * @return ValueExpressionResult
     */
    protected function analyzeClosureUnion(array $returns): array
    {
        /** @var list<string> $types */
        $types = [];
        /** @var list<ValueExpressionResult> $branchResults every non-null, non-unknown branch, for channel merging */
        $branchResults = [];
        $hasNull = false;

        foreach ($returns as $returnExpr) {
            // A guard-clause `return null;` is intercepted here so the standalone `null` union member is
            // tracked apart from object-shape branches; null as an *array value* goes through ConstFetch.
            if ($returnExpr instanceof ConstFetch
                && $returnExpr->name->toLowerString() === 'null') {
                $hasNull = true;

                continue;
            }

            $inner = $this->analyzeValueExpression($returnExpr);

            if ($inner['type'] === 'unknown') {
                continue; // @codeCoverageIgnore
            }

            $types[] = $inner['type'];
            $branchResults[] = $inner;
        }

        if ($hasNull) {
            $types[] = 'null';
        }

        $types = array_values(array_unique($types));

        // Drop a standalone 'null' when another member already carries null (e.g. 'number | null' from a
        // nullable column), which would otherwise render 'number | null | null'. Splitting on ' | ' is
        // safe for inline object types, since their trailing `}` prevents 'null }' from matching.
        $explicitNullIndex = array_search('null', $types, true);

        if ($explicitNullIndex !== false && count($types) > 1) {
            $otherTypes = array_values(array_filter($types, fn (string $t): bool => $t !== 'null'));
            $alreadyHasNull = false;

            foreach ($otherTypes as $t) {
                if (in_array('null', explode(' | ', $t), true)) {
                    $alreadyHasNull = true;

                    break;
                }
            }

            if ($alreadyHasNull) {
                unset($types[$explicitNullIndex]);
                $types = array_values($types);
            }
        }

        if ($types === []) {
            return $this->unknownResult(); // @codeCoverageIgnore
        }

        return $this->mergeUnionChannels($types, $branchResults);
    }

    /**
     * Fold union member types and their branch results into one ValueExpressionResult, carrying every
     * FQCN/import channel across so no emitted token loses its import.
     *
     * Shared by the ternary/closure union and by coalesce, which computes its own member list.
     *
     * @param  list<string>  $types
     * @param  list<ValueExpressionResult>  $branchResults
     * @return ValueExpressionResult
     */
    protected function mergeUnionChannels(array $types, array $branchResults): array
    {
        /** @var list<class-string> $enumResourceFqcns FQCNs from EnumResource::make() / new EnumResource() branches */
        $enumResourceFqcns = [];
        /** @var list<class-string> $enumDirectFqcns FQCNs from direct $this->prop enum-access branches */
        $enumDirectFqcns = [];
        /** @var list<class-string> $embeddedEnumFqcns FQCNs embedded inside nested inline-object types */
        $embeddedEnumFqcns = [];
        /** @var list<class-string> $embeddedModelFqcns */
        $embeddedModelFqcns = [];
        /** @var list<class-string> $embeddedResourceFqcns */
        $embeddedResourceFqcns = [];
        /** @var TypesImportMap $customImports */
        $customImports = [];

        foreach ($branchResults as $inner) {
            // EnumResource branches are tracked apart from direct-access ones, so the result can
            // propagate the correct FQCN metadata.
            if (isset($inner['enumFqcn'])) {
                $enumResourceFqcns[] = $inner['enumFqcn'];
            }

            if (isset($inner['directEnumFqcn'])) {
                $enumDirectFqcns[] = $inner['directEnumFqcn'];
            }

            if (isset($inner['embeddedEnumFqcns'])) {
                array_push($embeddedEnumFqcns, ...$inner['embeddedEnumFqcns']);
            }

            if (isset($inner['embeddedModelFqcns'])) {
                array_push($embeddedModelFqcns, ...$inner['embeddedModelFqcns']);
            }

            if (isset($inner['embeddedResourceFqcns'])) {
                array_push($embeddedResourceFqcns, ...$inner['embeddedResourceFqcns']);
            }

            if (isset($inner['resourceFqcn'])) {
                $embeddedResourceFqcns[] = $inner['resourceFqcn'];
            }

            if (isset($inner['modelFqcn'])) {
                $embeddedModelFqcns[] = $inner['modelFqcn'];
            }

            foreach ($inner['customImports'] ?? [] as $path => $importTypes) {
                $customImports[$path] = [...($customImports[$path] ?? []), ...$importTypes];
            }
        }

        $result = ['type' => implode(' | ', $types), 'optional' => false];

        $enumResourceFqcns = array_values(array_unique($enumResourceFqcns));
        $enumDirectFqcns = array_values(array_unique($enumDirectFqcns));
        $embeddedEnumFqcns = array_values(array_unique($embeddedEnumFqcns));
        $embeddedModelFqcns = array_values(array_unique($embeddedModelFqcns));
        $embeddedResourceFqcns = array_values(array_unique($embeddedResourceFqcns));

        if ($enumResourceFqcns !== []) {
            $allBranchFqcns = array_values(array_unique([...$enumResourceFqcns, ...$enumDirectFqcns]));

            if ($enumDirectFqcns === [] && count($enumResourceFqcns) === 1) {
                // Pure EnumResource, single FQCN.
                $result['enumFqcn'] = $enumResourceFqcns[0];
            } elseif ($enumDirectFqcns !== [] && count($allBranchFqcns) === 1) {
                // Mixed: same FQCN via EnumResource and via direct access.
                $result['enumFqcn'] = $allBranchFqcns[0];
                $result['directEnumFqcn'] = $allBranchFqcns[0];
            } elseif ($enumDirectFqcns === []
                && count($enumResourceFqcns) > 1
                && count($enumResourceFqcns) === count($types)
            ) {
                // All non-null branches are EnumResource with different FQCNs.
                // Emit ordered list so the transformer can do per-token AsEnum rewrite.
                $result['multiEnumResourceFqcns'] = $enumResourceFqcns;
            } else {
                // Multiple different FQCNs or complex mixed branches: fall back to embedded imports.
                $embeddedEnumFqcns = array_values(array_unique([...$allBranchFqcns, ...$embeddedEnumFqcns]));
            }
        } elseif ($enumDirectFqcns !== []) {
            // Only direct-access enum branches: existing embedded behaviour.
            $embeddedEnumFqcns = array_values(array_unique([...$enumDirectFqcns, ...$embeddedEnumFqcns]));
        }

        if ($embeddedEnumFqcns !== []) {
            $result['embeddedEnumFqcns'] = $embeddedEnumFqcns;
        }

        if ($embeddedModelFqcns !== []) {
            $result['embeddedModelFqcns'] = $embeddedModelFqcns;
        }

        if ($embeddedResourceFqcns !== []) {
            $result['embeddedResourceFqcns'] = $embeddedResourceFqcns;
        }

        if ($customImports !== []) {
            $result['customImports'] = $customImports;
        }

        return $result;
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
        // same one analyzeWhenLoaded() already populated for a to-many param.
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
            default => $this->analyzeClosureUnion($returnExprs),
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
            $info = $this->analyzeRelatedModelProperty($fieldName);

            if ($info['type'] !== 'unknown') {
                $info['type'] = $this->arrayWrapType($info['type']);
                $info['optional'] = false;

                return $info;
            }
        }

        return ['type' => 'unknown[]', 'optional' => false];
    }

    /**
     * Bind a closure's first parameter to the `$this->propName` expression found in a `when()` condition,
     * so `EnumResource::make($status)` resolves as if it were `EnumResource::make($this->status)`.
     */
    private function bindClosureParamsFromCondition(Expr $condition, Expr $valueExpr): void
    {
        $thisPropExpr = $this->extractThisPropertyFromCondition($condition);

        if ($thisPropExpr === null) {
            return;
        }

        $firstParam = null;

        if ($valueExpr instanceof ArrowFunction && $valueExpr->params !== []) {
            $firstParam = $valueExpr->params[0];
        } elseif ($valueExpr instanceof ClosureExpr && $valueExpr->params !== []) {
            $firstParam = $valueExpr->params[0];
        }

        if ($firstParam === null) {
            return;
        }

        if ($firstParam->var instanceof Variable && is_string($firstParam->var->name)) {
            $this->scope->closureParamExprBindings[$firstParam->var->name] = $thisPropExpr;
        }
    }

    /**
     * Extract a `$this->propName` PropertyFetch from a boolean condition, whether used bare as a
     * truthy test or compared identically against null in either operand order.
     */
    private function extractThisPropertyFromCondition(Expr $condition): ?Expr
    {
        if ($this->isThisPropertyFetch($condition)) {
            return $condition;
        }

        if ($condition instanceof BinaryOp\NotIdentical) {
            if ($this->isThisPropertyFetch($condition->left) && $this->isNullConstFetch($condition->right)) {
                return $condition->left;
            }

            if ($this->isThisPropertyFetch($condition->right) && $this->isNullConstFetch($condition->left)) {
                return $condition->right;
            }
        }

        if ($condition instanceof BinaryOp\Identical) {
            if ($this->isThisPropertyFetch($condition->left) && $this->isNullConstFetch($condition->right)) {
                return $condition->left;
            }

            if ($this->isThisPropertyFetch($condition->right) && $this->isNullConstFetch($condition->left)) {
                return $condition->right;
            }
        }

        return null;
    }

    /**
     * Return true when the expression is a `null` constant fetch.
     */
    private function isNullConstFetch(Expr $expr): bool
    {
        return $expr instanceof ConstFetch && strtolower($expr->name->toString()) === 'null';
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
