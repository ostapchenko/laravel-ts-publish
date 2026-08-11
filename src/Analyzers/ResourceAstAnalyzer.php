<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Analyzers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\FiltersModelAttributes;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\ResolvesModelTypes;
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
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast\Array_ as CastArray_;
use PhpParser\Node\Expr\Cast\Bool_ as CastBool;
use PhpParser\Node\Expr\Cast\Double as CastDouble;
use PhpParser\Node\Expr\Cast\Int_ as CastInt;
use PhpParser\Node\Expr\Cast\String_ as CastString;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Isset_;
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
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\InterpolatedString;
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
 * @phpstan-import-type ResourcePropertyInfoList from ResourceAnalysis
 * @phpstan-import-type ClassMapType from ResourceAnalysis
 * @phpstan-import-type ImportMapType from ResourceAnalysis
 * @phpstan-import-type InlineEnumFqcnsMap from ResourceAnalysis
 * @phpstan-import-type InlineModelFqcnsMap from ResourceAnalysis
 * @phpstan-import-type MultiEnumFqcnsMap from ResourceAnalysis
 * @phpstan-import-type TypeScriptTypeInfo from \AbeTwoThree\LaravelTsPublish\LaravelTsPublish
 * @phpstan-import-type TypesImportMap from Datable
 *
 * @phpstan-type ValueExpressionResult = array{
 *      type: string,
 *      optional: bool,
 *      enumFqcn?: class-string,
 *      directEnumFqcn?: class-string,
 *      resourceFqcn?: class-string,
 *      modelFqcn?: class-string,
 *      embeddedEnumFqcns?: list<class-string>,
 *      embeddedEnumResourceFqcns?: list<class-string>,
 *      embeddedModelFqcns?: list<class-string>,
 *      embeddedResourceFqcns?: list<class-string>,
 *      multiEnumResourceFqcns?: list<class-string>,
 *      customImports?: TypesImportMap
 * }
 * @phpstan-type ClosureAnnotationResult = array{
 *      type: string,
 *      directEnumFqcn?: class-string,
 *      modelFqcn?: class-string
 * }
 */
class ResourceAstAnalyzer
{
    use FiltersModelAttributes;
    use InspectsAstNodes;
    use ResolvesClassNames;
    use ResolvesModelTypes;

    /**
     * Wrapped class from an `instanceof` guard in toArray(); fallback when resolveClassOnProperty() returns null.
     *
     * @var class-string|null
     */
    protected ?string $instanceOfWrappedClass = null;

    /**
     * Related model set while analyzing a whenLoaded closure, so `$variable->prop`/`->method()` inside it resolve.
     *
     * @var class-string<Model>|null
     */
    protected ?string $closureRelationModelClass = null;

    /**
     * Closure parameter names bound to the `$this->prop` expression found in the surrounding `when()`
     * condition, so `EnumResource::make($status)` resolves like `EnumResource::make($this->status)`.
     *
     * @var array<string, Expr>
     */
    protected array $closureParamExprBindings = [];

    /**
     * Top-level `$var = expr;` bindings for the method last analyzed, so a bare `Variable` value
     * expression resolves through its bound expression instead of degrading to unknown. Only variables
     * written exactly once are recorded; analyzeThisMethodSpread() saves and restores this per method.
     *
     * @var array<string, Expr>
     */
    protected array $localVarBindings = [];

    /**
     * Re-entrancy guard: variable names currently mid-resolution, so a self- or mutually-referential
     * binding (e.g. `$a = $b; $b = $a;`) resolves as unknown instead of recursing forever.
     *
     * @var array<string, true>
     */
    protected array $resolvingLocalVars = [];

    /**
     * Create an analyzer for a resource class and its optional backing model.
     *
     * @param  ReflectionClass<JsonResource>  $resourceReflection
     * @param  class-string<Model>|null  $modelClass
     */
    public function __construct(
        protected ReflectionClass $resourceReflection,
        protected ?string $modelClass = null,
    ) {
        if ($this->modelClass !== null) {
            $this->loadModelInspectorData();
        }
    }

    /**
     * Analyze the resource's toArray() and return the resulting property/type analysis.
     */
    public function analyze(): ResourceAnalysis
    {
        if ($this->modelClass !== null) {
            DependencyRecorder::recordClass($this->modelClass);
        }

        $filePath = (string) $this->resourceReflection->getFileName();
        $source = (string) file_get_contents($filePath);

        $stmts = $this->parseAndResolveAst($source);

        $finder = new NodeFinder;
        $toArrayMethod = $finder->findFirst($stmts, function (Node $node): bool {
            return $node instanceof ClassMethod && $node->name->toString() === 'toArray';
        });

        if (! $toArrayMethod instanceof ClassMethod || $toArrayMethod->stmts === null) {
            if ($this->isResourceCollection()) {
                return $this->buildCollectionDelegatedAnalysis();
            }

            return $this->buildModelDelegatedAnalysis() ?? new ResourceAnalysis;
        }

        $this->instanceOfWrappedClass = $this->resolveInstanceOfType($toArrayMethod, $finder);

        $this->collectLocalVarBindings($toArrayMethod->stmts);

        $branchAnalysis = $this->analyzeAllReturnBranches($toArrayMethod->stmts);

        if ($branchAnalysis !== null) {
            if ($this->resourceReflection->hasMethod('toArray')) {
                $this->applyTsCastsFromMethod($this->resourceReflection->getMethod('toArray'), $branchAnalysis);
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
            return $this->analyzeThisAttributeFilter($returnStmt->expr) ?? new ResourceAnalysis;
        }

        return new ResourceAnalysis;
    }

    /**
     * Record top-level `$var = expr;` statements so property values referencing those variables resolve.
     *
     * A variable written more than once anywhere in the method is skipped: this flat statement list has no
     * notion of which write is live at a given return branch (analyzeAllReturnBranches() analyzes each
     * branch independently), so binding one of them risks a wrong-but-plausible type rather than unknown.
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
                $this->localVarBindings[$stmt->expr->var->name] = $stmt->expr->expr;
            }
        }
    }

    /**
     * Collect every local variable name written anywhere in a statement tree, via any assignment or
     * mutation form, including `foreach` key/value targets and destructuring leaves. `AssignRef` counts
     * both sides, since `$alias = &$x;` makes any later write to `$alias` mutate `$x` too. By-reference
     * call arguments (e.g. `preg_match(..., $matches)`) are a known gap — detecting them needs the
     * callee's signature, which is not statically knowable for dynamic callables.
     *
     * Closure and arrow-function parameters count too: they rebind the name, so an outer local of the
     * same name would otherwise leak into the closure body. A by-value `use ($x)` is not a write — it
     * carries the outer value in — while `use (&$x)` aliases it, exactly like AssignRef.
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
                || $node instanceof ClosureExpr
                || $node instanceof ArrowFunction,
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
            } elseif ($node instanceof ClosureExpr || $node instanceof ArrowFunction) {
                foreach ($node->params as $param) {
                    $targets[] = $param->var;
                }

                if ($node instanceof ClosureExpr) {
                    foreach ($node->uses as $use) {
                        if ($use->byRef) {
                            $targets[] = $use->var;
                        }
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
            // Handle ...parent::toArray($request) spread
            if ($item->key === null && $item->unpack && $this->isParentToArrayCall($item->value)) {
                $parentAnalysis = $this->analyzeParentToArray();

                if ($parentAnalysis !== null) {
                    $this->syncAnalysisMaps(
                        $properties, $enumResources, $nestedResources,
                        $directEnumFqcns, $modelFqcns, $customImports,
                        $parentAnalysis, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
                        $inlineEnumResourceFqcns,
                    );
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
                    $this->syncAnalysisMaps(
                        $properties, $enumResources, $nestedResources,
                        $directEnumFqcns, $modelFqcns, $customImports,
                        $filterAnalysis, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
                        $inlineEnumResourceFqcns,
                    );
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
                    $this->syncAnalysisMaps(
                        $properties, $enumResources, $nestedResources,
                        $directEnumFqcns, $modelFqcns, $customImports,
                        $spreadAnalysis, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
                        $inlineEnumResourceFqcns,
                    );
                }

                continue;
            }

            // Handle ...functionCall() spread (bare trait method calls without $this->)
            if ($item->key === null && $item->unpack && $item->value instanceof FuncCall) {
                /** @var Node $funcCallName */
                $funcCallName = $item->value->name;

                if ($funcCallName instanceof Name) {
                    $funcName = $funcCallName->getLast();

                    if ($this->resourceReflection->hasMethod($funcName)) {
                        $spreadAnalysis = $this->analyzeThisMethodSpread($funcName);

                        if ($spreadAnalysis !== null) {
                            $this->syncAnalysisMaps(
                                $properties, $enumResources, $nestedResources,
                                $directEnumFqcns, $modelFqcns, $customImports,
                                $spreadAnalysis, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
                                $inlineEnumResourceFqcns,
                            );
                        }
                    }
                }

                continue;
            }

            // Handle $this->merge([...]) or $this->mergeWhen(condition, [...])
            if ($item->key === null && $item->value instanceof MethodCall) {
                $mergeResult = $this->analyzeMergeExpression($item->value);

                $this->syncAnalysisMaps(
                    $properties, $enumResources, $nestedResources,
                    $directEnumFqcns, $modelFqcns, $customImports,
                    $mergeResult, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
                    $inlineEnumResourceFqcns,
                );

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
            unset($enumResources[$keyName], $nestedResources[$keyName], $directEnumFqcns[$keyName], $modelFqcns[$keyName], $multiEnumResourceFqcns[$keyName]);

            $properties[] = [
                'name' => $keyName,
                'type' => $result['type'],
                'optional' => $result['optional'],
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
     * Merge a ResourceAnalysis result into the running accumulator arrays.
     *
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
    protected function syncAnalysisMaps(
        array &$properties,
        array &$enumResources,
        array &$nestedResources,
        array &$directEnumFqcns,
        array &$modelFqcns,
        array &$customImports,
        ResourceAnalysis $source,
        array &$inlineEnumFqcns = [],
        array &$inlineModelFqcns = [],
        array &$multiEnumResourceFqcns = [],
        array &$inlineEnumResourceFqcns = [],
    ): void {
        $properties = [...$properties, ...$source->properties];
        $enumResources = [...$enumResources, ...$source->enumResources];
        $nestedResources = [...$nestedResources, ...$source->nestedResources];
        $directEnumFqcns = [...$directEnumFqcns, ...$source->directEnumFqcns];
        $modelFqcns = [...$modelFqcns, ...$source->modelFqcns];
        $multiEnumResourceFqcns = [...$multiEnumResourceFqcns, ...$source->multiEnumResourceFqcns];

        foreach ($source->customImports as $path => $types) {
            $customImports[$path] = [...($customImports[$path] ?? []), ...$types];
        }

        foreach ($source->inlineEnumFqcns as $propName => $fqcns) {
            $inlineEnumFqcns[$propName] = array_values(array_unique(
                [...($inlineEnumFqcns[$propName] ?? []), ...$fqcns]
            ));
        }

        foreach ($source->inlineModelFqcns as $propName => $fqcns) {
            $inlineModelFqcns[$propName] = array_values(array_unique(
                [...($inlineModelFqcns[$propName] ?? []), ...$fqcns]
            ));
        }

        foreach ($source->inlineEnumResourceFqcns as $propName => $fqcns) {
            $inlineEnumResourceFqcns[$propName] = array_values(array_unique(
                [...($inlineEnumResourceFqcns[$propName] ?? []), ...$fqcns]
            ));
        }
    }

    /**
     * Analyze a value expression and return its type + optional status.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeValueExpression(Expr $expr): array
    {
        $result = $this->unknownResult();

        // First-class callables (e.g. $this->when(...)) have no args — bail early
        if ($expr instanceof MethodCall && $expr->isFirstClassCallable()) {
            return $result; // @codeCoverageIgnore
        }

        // PHP cast operators — the cast alone determines the type, not the inner expression.
        if ($expr instanceof CastBool) {
            return ['type' => 'boolean', 'optional' => false];
        }

        if ($expr instanceof CastInt || $expr instanceof CastDouble) {
            return ['type' => 'number', 'optional' => false];
        }

        if ($expr instanceof CastString) {
            return ['type' => 'string', 'optional' => false];
        }

        if ($expr instanceof CastArray_) {
            return ['type' => 'unknown[]', 'optional' => false];
        }

        if ($expr instanceof String_ || $expr instanceof InterpolatedString) {
            return ['type' => 'string', 'optional' => false];
        }

        if ($expr instanceof Int_ || $expr instanceof Float_) {
            return ['type' => 'number', 'optional' => false];
        }

        // Unary +/- always yield a number; non-literal operands are assumed numeric (variable types aren't tracked).
        if ($expr instanceof UnaryMinus || $expr instanceof UnaryPlus) {
            return ['type' => 'number', 'optional' => false];
        }

        if ($expr instanceof ConstFetch) {
            $constName = $expr->name->toLowerString();
            if ($constName === 'null') {
                return ['type' => 'null', 'optional' => false];
            }
            if (in_array($constName, ['true', 'false'], true)) {
                return ['type' => 'boolean', 'optional' => false];
            }
        }

        // Arithmetic always yields a number. Also catches `(int) round(...) / 2`: PHP precedence binds the
        // cast tighter than the division, so the outer node is a BinaryOp\Div rather than a Cast.
        if ($expr instanceof BinaryOp\Plus
            || $expr instanceof BinaryOp\Minus
            || $expr instanceof BinaryOp\Mul
            || $expr instanceof BinaryOp\Div
            || $expr instanceof BinaryOp\Mod
            || $expr instanceof BinaryOp\Pow
        ) {
            return ['type' => 'number', 'optional' => false];
        }

        if ($expr instanceof BinaryOp\Concat) {
            return ['type' => 'string', 'optional' => false];
        }

        // Comparison, logical, and type-test operators always produce a boolean. PHP's &&/|| return bool,
        // unlike JS — even as a null-guard (`$this->x && $this->x->y`), no false|T union is needed.
        if ($expr instanceof BinaryOp\Identical
            || $expr instanceof BinaryOp\NotIdentical
            || $expr instanceof BinaryOp\Equal
            || $expr instanceof BinaryOp\NotEqual
            || $expr instanceof BinaryOp\Greater
            || $expr instanceof BinaryOp\GreaterOrEqual
            || $expr instanceof BinaryOp\Smaller
            || $expr instanceof BinaryOp\SmallerOrEqual
            || $expr instanceof BinaryOp\BooleanAnd
            || $expr instanceof BinaryOp\BooleanOr
            || $expr instanceof BinaryOp\LogicalAnd
            || $expr instanceof BinaryOp\LogicalOr
            || $expr instanceof BinaryOp\LogicalXor
            || $expr instanceof BooleanNot
            || $expr instanceof Instanceof_
            || $expr instanceof Isset_
            || $expr instanceof Empty_
        ) {
            return ['type' => 'boolean', 'optional' => false];
        }

        // Spaceship comparison produces -1|0|1.
        if ($expr instanceof BinaryOp\Spaceship) {
            return ['type' => 'number', 'optional' => false];
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
        }

        if ($this->isThisMethodCall($expr, 'when')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhen($expr);
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
            return ['type' => 'number', 'optional' => true];
        }

        if ($this->isThisMethodCall($expr, 'whenAggregated')) {
            return ['type' => 'number', 'optional' => true];
        }

        if ($this->isThisMethodCall($expr, 'whenPivotLoaded') || $this->isThisMethodCall($expr, 'whenPivotLoadedAs')) {
            return ['type' => 'unknown', 'optional' => true];
        }

        // `$variable::staticMethod()` in a whenLoaded closure. Must precede the general StaticCall
        // handler, which only matches class-name receivers.
        if ($this->closureRelationModelClass !== null
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
            $closureModelClass = $this->closureRelationModelClass;

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

            if ($info['type'] === 'unknown' && $this->closureRelationModelClass !== null && $expr->name instanceof Identifier) {
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
            $closureModelClass = $this->closureRelationModelClass;

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

        /** @var class-string<Model>|null $closureModelClass */
        $closureModelClass = $this->closureRelationModelClass;

        // $variable->property — resolve against the related model in a whenLoaded closure context
        if ($closureModelClass !== null
            && $expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && $expr->var->name !== 'this'
            && $expr->name instanceof Identifier
        ) {
            return $this->analyzeRelatedModelProperty($expr->name->toString());
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
        if ($this->closureRelationModelClass !== null
            && $expr instanceof MethodCall
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && $expr->var->name !== 'this'
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'pluck'
        ) {
            return $this->analyzeVariablePluckCall($expr);
        }

        // $variable->method() — resolve against the related model in a whenLoaded closure context
        if ($this->closureRelationModelClass !== null
            && $expr instanceof MethodCall
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && $expr->var->name !== 'this'
            && $expr->name instanceof Identifier
        ) {
            return $this->analyzeRelatedModelMethodCall($expr->name->toString());
        }

        if ($expr instanceof Ternary) {
            return $this->analyzeTernary($expr);
        }

        // Bare variable bound either to a closure parameter (bindClosureParamsFromCondition) or to a
        // top-level local assignment (collectLocalVarBindings). Closure-param bindings win, being the
        // narrower scope; the re-entrancy guard makes a cyclic binding resolve as unknown.
        if ($expr instanceof Variable && is_string($expr->name)) {
            $boundExpr = $this->closureParamExprBindings[$expr->name]
                ?? $this->localVarBindings[$expr->name]
                ?? null;

            if ($boundExpr !== null && ! isset($this->resolvingLocalVars[$expr->name])) {
                $this->resolvingLocalVars[$expr->name] = true;

                try {
                    return $this->analyzeValueExpression($boundExpr);
                } finally {
                    unset($this->resolvingLocalVars[$expr->name]);
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
     * Both operands are resolved and unioned when they differ; a `null` left type is treated as
     * unknown, because the coalesce operator guarantees the fallback is used instead.
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
        $leftType = trim(str_replace('| null', '', $leftType));
        $leftType = trim(str_replace('null |', '', $leftType));

        if ($leftType === 'unknown' || $leftType === '') {
            return ['type' => $rightType, 'optional' => false];
        }

        if ($rightType === 'unknown') {
            return ['type' => $leftType, 'optional' => false];
        }

        if ($leftType === $rightType) {
            return ['type' => $leftType, 'optional' => false];
        }

        return ['type' => $leftType.' | '.$rightType, 'optional' => false];
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

            $previousBindings = $this->closureParamExprBindings;
            $this->bindClosureParamsFromCondition($args[0]->value, $valueExpr);

            $inner = $this->analyzeValueExpression($valueExpr);
            $inner['optional'] = true;

            $this->closureParamExprBindings = $previousBindings;

            return $inner;
        }

        return [...$result, 'optional' => true]; // @codeCoverageIgnore
    }

    /**
     * Analyze $this->whenHas('attribute') — the attribute name is the first arg string.
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
            $result = ['type' => $info['type'], 'optional' => true];

            if ($info['enumFqcn'] !== null) {
                $result['directEnumFqcn'] = $info['enumFqcn'];
            }

            return $result;
        }

        return [...$result, 'optional' => true]; // @codeCoverageIgnore
    }

    /**
     * Analyze $this->whenNotNull($this->value, $callback) — resolve the callback expression type.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenNotNull(MethodCall $call): array
    {
        return $this->analyzeWhenPossiblyNull($call);
    }

    /**
     * Analyze $this->whenNull($this->value, $callback) — resolve the callback expression type.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenNull(MethodCall $call): array
    {
        return $this->analyzeWhenPossiblyNull($call);
    }

    /**
     * Shared logic for whenNotNull()/whenNull(): analyze the callback and bind its closure param.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenPossiblyNull(MethodCall $call): array
    {
        $result = $this->unknownResult();
        $args = $call->getArgs();

        if (count($args) === 1) {
            $inner = $this->analyzeValueExpression($args[0]->value);
            $inner['optional'] = true;

            return $inner;
        }

        if (count($args) >= 2) {
            $valueExpr = $args[1]->value;
            $previousBindings = $this->closureParamExprBindings;

            $this->bindClosureParamsFromCondition($args[0]->value, $valueExpr);
            $inner = $this->analyzeValueExpression($valueExpr);
            $inner['optional'] = true;

            $this->closureParamExprBindings = $previousBindings;

            return $inner;
        }

        return [...$result, 'optional' => true]; // @codeCoverageIgnore
    }

    /**
     * Analyze $this->whenLoaded('relation') or $this->whenLoaded('relation', value, default).
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenLoaded(MethodCall $call): array
    {
        $result = $this->unknownResult();
        $args = $call->getArgs();

        if (count($args) >= 2) {
            // Resolve the related model so accesses on local variables inside the closure can be typed.
            $previousRelationModel = $this->closureRelationModelClass;

            if ($args[0]->value instanceof String_) {
                $info = $this->resolveModelRelationTypeInfo($args[0]->value->value);

                if (($info['modelFqcn'] ?? null) !== null) {
                    $this->closureRelationModelClass = $info['modelFqcn'];
                }
            }

            $inner = $this->analyzeValueExpression($args[1]->value);
            $inner['optional'] = true;

            $this->closureRelationModelClass = $previousRelationModel;

            return $inner;
        }

        if (count($args) >= 1 && $args[0]->value instanceof String_) {
            $relationName = $args[0]->value->value;
            $info = $this->resolveModelRelationTypeInfo($relationName);
            $result = ['type' => $info['type'], 'optional' => true];

            if ($info['modelFqcn'] !== null) {
                $result['modelFqcn'] = $info['modelFqcn'];
            }

            if ($info['morphFqcns'] !== []) {
                $result['embeddedModelFqcns'] = $info['morphFqcns'];
            }

            return $result;
        }

        return [...$result, 'optional' => true]; // @codeCoverageIgnore
    }

    /**
     * Analyze $this->merge([...]) or $this->mergeWhen(condition, [...]) — extract properties from the array arg.
     *
     * merge() properties are required; mergeWhen() properties are optional.
     */
    protected function analyzeMergeExpression(MethodCall $call): ResourceAnalysis
    {
        $isMerge = $this->isThisMethodCall($call, 'merge');
        $isMergeWhen = $this->isThisMethodCall($call, 'mergeWhen');

        if (! $isMerge && ! $isMergeWhen) {
            return new ResourceAnalysis; // @codeCoverageIgnore
        }

        if ($call->isFirstClassCallable()) {
            return new ResourceAnalysis; // @codeCoverageIgnore
        }

        $args = $call->getArgs();

        if ($isMerge && count($args) >= 1) {
            return $this->resolveArrayOrClosureToProperties($args[0]->value, optional: false);
        }

        if ($isMergeWhen && count($args) >= 2) {
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
            $className = $this->resourceReflection->getName();
        }

        // EnumResource::make($this->prop)
        if ($this->isEnumResourceClass($className) && $methodName === 'make') {
            return $this->analyzeEnumResourceMake($call);
        }

        // SomeCollection::make()/::collection() on a ResourceCollection subclass. Must precede the generic
        // checks below: ResourceCollection extends JsonResource, so isResourceClass() matches it too and
        // would yield the unsuffixed collection name instead of 'OrderItemResource[]'.
        if (is_a($className, ResourceCollection::class, true) && in_array($methodName, ['make', 'collection'], true)) {
            $collected = $this->collectedResourceClass($className);

            if ($collected !== null) {
                return [
                    ...$result,
                    'type' => class_basename($collected).'[]',
                    'optional' => $this->hasConditionalArgument($call),
                    'resourceFqcn' => $collected,
                ];
            }
        }

        // SomeResource::make($this->prop) — nested resource
        if ($this->isResourceClass($className) && $methodName === 'make') {
            $resourceName = class_basename($className);
            $optional = $this->hasConditionalArgument($call);

            /** @var class-string $className */
            return [
                ...$result,
                'type' => $resourceName,
                'optional' => $optional,
                'resourceFqcn' => $className,
            ];
        }

        // SomeResource::collection(...) — array of nested resource
        if ($this->isResourceClass($className) && $methodName === 'collection') {
            $resourceName = class_basename($className);
            $optional = $this->hasConditionalArgument($call);

            /** @var class-string $className */
            return [
                ...$result,
                'type' => $resourceName.'[]',
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
     * Resolve the resource class a ResourceCollection collects, from the #[Collects] attribute, the
     * $collects property default, or the FooCollection → FooResource naming convention.
     *
     * @param  class-string  $collectionFqcn
     * @return class-string<JsonResource>|null
     */
    protected function collectedResourceClass(string $collectionFqcn): ?string
    {
        $reflection = new ReflectionClass($collectionFqcn);

        $collectsAttribute = 'Illuminate\Http\Resources\Attributes\Collects';
        if (class_exists($collectsAttribute)) {
            // Priority 1: #[Collects] attribute (Laravel 12+)
            $collectsAttrs = $reflection->getAttributes($collectsAttribute);

            if ($collectsAttrs !== []) {
                $collectsClass = $collectsAttrs[0]->newInstance()->class;

                if (class_exists($collectsClass) && is_a($collectsClass, JsonResource::class, true)) {
                    return $collectsClass;
                }
            }
        }

        // Priority 2: explicit $collects property default value
        /** @var array<string, mixed> $defaults */
        $defaults = $reflection->getDefaultProperties();
        $collects = $defaults['collects'] ?? null;

        if (is_string($collects) && class_exists($collects) && is_a($collects, JsonResource::class, true)) {
            return $collects;
        }

        // Priority 3: naming convention — FooCollection → FooResource
        $className = $reflection->getShortName();
        $namespace = $reflection->getNamespaceName();

        if (str_ends_with($className, 'Collection')) {
            $base = substr($className, 0, -10);

            $candidate = $namespace.'\\'.$base.'Resource';

            if (class_exists($candidate) && is_a($candidate, JsonResource::class, true)) {
                return $candidate;
            }

            $candidate = $namespace.'\\'.$base; // @codeCoverageIgnoreStart

            if (class_exists($candidate) && is_a($candidate, JsonResource::class, true)) {
                return $candidate;
            } // @codeCoverageIgnoreEnd
        }

        return null;
    }

    /**
     * Accept a reflected TypeScriptTypeInfo as a ValueExpressionResult only when every
     * referenced type can be imported: primitives verbatim; enums via the single slot or
     * the embedded list; Model classes via modelFqcn/embeddedModelFqcns; TsType custom
     * imports carried through. A non-Model class token has no published file to import,
     * so it still rejects the whole result.
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
            $className = $this->resourceReflection->getName();
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
                    'type' => class_basename($collected).'[]',
                    'optional' => $this->hasConditionalNewArgument($expr),
                    'resourceFqcn' => $collected,
                ];
            }
        }

        if (! $this->isResourceClass($className)) {
            return $result; // @codeCoverageIgnore
        }

        $resourceName = class_basename($className);
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
                $boundExpr = $this->closureParamExprBindings[$argExpr->name] ?? null;

                if ($boundExpr !== null) {
                    return $this->resolveEnumFromPropertyArg($boundExpr);
                }
            }

            // Handle $variable->property inside a whenLoaded closure.
            if (
                $argExpr instanceof PropertyFetch
                && $argExpr->var instanceof Variable
                && $argExpr->name instanceof Identifier
                && $this->closureRelationModelClass !== null
            ) {
                $propName = $argExpr->name->toString();
                $tsInfo = resolve(ModelAttributeResolver::class)->resolveAttribute($this->closureRelationModelClass, $propName);

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
        }

        return new ResourceAnalysis(
            $properties,
            $enumResources,
            $nestedResources,
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
        $parentClass = $this->resourceReflection->getParentClass();

        if ($parentClass === false || ! is_a($parentClass->getName(), JsonResource::class, true)) {
            return null; // @codeCoverageIgnore
        }

        if ($parentClass->getName() === JsonResource::class) {
            return $this->buildModelDelegatedAnalysis();
        }

        $parentAnalyzer = new self(
            $parentClass,
            $this->modelClass,
        );

        return $parentAnalyzer->analyze();
    }

    /**
     * Resolve and analyze a $this->method() spread from a trait or the class itself.
     *
     * $localVarBindings/$resolvingLocalVars are scoped to a single method's statement list, so they are
     * saved, cleared, re-collected for the target method, and restored via `finally` — a caller's `$data`
     * and a same-named `$data` here are different variables, and the caller may still be mid-analysis.
     */
    protected function analyzeThisMethodSpread(string $methodName): ?ResourceAnalysis
    {
        if (! $this->resourceReflection->hasMethod($methodName)) {
            return null; // @codeCoverageIgnore
        }

        $method = $this->resourceReflection->getMethod($methodName);
        $filePath = $method->getFileName();

        if ($filePath === false) {
            return null; // @codeCoverageIgnore
        }

        $source = (string) file_get_contents($filePath);
        $stmts = $this->parseAndResolveAst($source);

        $finder = new NodeFinder;
        $targetMethod = $finder->findFirst($stmts, function (Node $node) use ($methodName): bool {
            return $node instanceof ClassMethod && $node->name->toString() === $methodName;
        });

        if (! $targetMethod instanceof ClassMethod || $targetMethod->stmts === null) {
            return null; // @codeCoverageIgnore
        }

        $previousLocalVarBindings = $this->localVarBindings;
        $previousResolvingLocalVars = $this->resolvingLocalVars;
        $this->localVarBindings = [];
        $this->resolvingLocalVars = [];
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
            } else {
                $analysis = new ResourceAnalysis;
            }
        } finally {
            $this->localVarBindings = $previousLocalVarBindings;
            $this->resolvingLocalVars = $previousResolvingLocalVars;
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
        $currentModel = $this->closureRelationModelClass ?? $this->modelClass;

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

        if ($this->closureRelationModelClass !== null && $count >= 2) {
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
        $currentModel = $this->closureRelationModelClass ?? $this->modelClass;

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

        if ($this->closureRelationModelClass !== null) {
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

                $this->syncAnalysisMaps(
                    $properties, $enumResources, $nestedResources,
                    $directEnumFqcns, $modelFqcns, $customImports,
                    $baseAnalysis, $inlineEnumFqcns, $inlineModelFqcns, $multiEnumResourceFqcns,
                    $inlineEnumResourceFqcns,
                );

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
        return $this->resourceReflection->isSubclassOf(ResourceCollection::class);
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
        $ownFqcn = $this->resourceReflection->getName();

        return $this->collectedResourceClass($ownFqcn);
    }

    /**
     * Build a ResourceAnalysis for a ResourceCollection subclass that has no toArray() method.
     *
     * A non-empty $wrap key produces a `{ data: SingularResource[] }` shape; a null $wrap sets
     * flatTypeAlias so the writer emits `export type X = SingularResource[]`.
     */
    protected function buildCollectionDelegatedAnalysis(): ResourceAnalysis
    {
        $singular = $this->resolveSingularResourceClass();

        if ($singular === null) {
            return new ResourceAnalysis;
        }

        // Read $wrap declared on this class only — inherited, JsonResource's static default is 'data'.
        $wrapKey = 'data';

        if ($this->resourceReflection->hasProperty('wrap')) {
            $wrapProp = $this->resourceReflection->getProperty('wrap');

            if ($wrapProp->getDeclaringClass()->getName() === $this->resourceReflection->getName()) {
                /** @var string|null $wrapKey */
                $wrapKey = $wrapProp->getDefaultValue();
            }
        }

        $singularBaseName = class_basename($singular);

        if ($wrapKey === null || $wrapKey === '') {
            return new ResourceAnalysis(flatTypeAlias: $singularBaseName.'[]', flatTypeAliasFqcn: $singular);
        }

        $key = $wrapKey ? $wrapKey : 'data';

        return new ResourceAnalysis(
            properties: [[
                'name' => $key,
                'type' => $singularBaseName.'[]',
                'optional' => false,
                'description' => '',
            ]],
            nestedResources: [$wrapKey => $singular],
        );
    }

    /**
     * Analyze $this->collection in a ResourceCollection, resolving it
     * to the singular resource type as an array.
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
            'type' => class_basename($singular).'[]',
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

            foreach ($modelFqcns as $fqcn) {
                $filterResult = $this->resolveFilteredRelationType($fqcn, $keys, $include);

                if ($filterResult['type'] !== 'unknown' && ! in_array($filterResult['type'], $inlineTypes, true)) {
                    $inlineTypes[] = $filterResult['type'];
                    array_push($embeddedEnumFqcns, ...$filterResult['enumFqcns']);
                    array_push($embeddedModelFqcns, ...$filterResult['modelFqcns']);
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
                'embeddedModelFqcns' => array_values(array_unique($embeddedModelFqcns)),
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
        ];
    }

    /**
     * Build a Pick<Model, …>/Omit<Model, …> reference when every filter key is a plain database column of
     * the related model, so the emitted model interface (with its TsCasts/@property refinements) stays the
     * single source of truth. Returns null when any key is not a column — callers fall back to inline
     * expansion (e.g. the key is an accessor/mutator, or names a relation instead of a column).
     *
     * Both wrappers target the bare model interface (columns only) unconditionally. This matches
     * Eloquent's actual runtime behaviour, not just a convenient simplification: HasAttributes::except()
     * iterates only $this->getAttributes() — relations live in a separate $this->relations property it
     * never reads — and HasAttributes::mergeAttributeFromAttributeCasts() explicitly refuses to merge a
     * get-only Attribute cast's cached value back into $this->attributes, so except() can never surface
     * one even after it has been accessed. Verified empirically in
     * tests/Feature/ModelOnlyExceptSemanticsTest.php against a real, DB-fetched model with a loaded
     * relation and touched accessors. See docs/components/resource-ast-analyzer.md.
     *
     * @param  class-string<Model>  $modelFqcn
     * @param  list<string>  $keys
     */
    protected function relationFilterModelReference(string $modelFqcn, array $keys, bool $include): ?string
    {
        $resolver = resolve(ModelAttributeResolver::class);
        $columns = $resolver->databaseColumnNames($modelFqcn);

        if ($columns === []) {
            return null; // @codeCoverageIgnore
        }

        foreach ($keys as $key) {
            if (! in_array($key, $columns, true)) {
                return null;
            }
        }

        $quoted = implode(' | ', array_map(fn (string $k): string => "'".$k."'", $keys));
        $wrapper = $include ? 'Pick' : 'Omit';

        return $wrapper.'<'.class_basename($modelFqcn).', '.$quoted.'>';
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

        if ($analysis->properties === []) {
            return ['type' => 'Record<string, unknown>', 'optional' => false];
        }

        $useTolki = Config::boolean('ts-publish.enums.use_tolki_package');

        $parts = array_map(function (array $prop) use ($analysis, $useTolki): string {
            $key = LaravelTsPublish::validJsObjectKey($prop['name']);

            $type = $prop['type'];

            // Tolki on: EnumResource-wrapped properties render as `AsEnum<typeof X>`, matching the
            // top-level enum resource transformer.
            if ($useTolki && isset($analysis->enumResources[$prop['name']])) {
                $fqcn = $analysis->enumResources[$prop['name']];
                $tsInfo = LaravelTsPublish::toTsType($fqcn);
                $constName = $tsInfo['enums'][0] ?? class_basename($fqcn);
                $nullable = str_contains($type, 'null');
                $type = 'AsEnum<typeof '.$constName.'>'.($nullable ? ' | null' : '');
            }

            return $prop['optional']
                ? "{$key}?: {$type}"
                : "{$key}: {$type}";
        }, $analysis->properties);

        $result = ['type' => '{ '.implode('; ', $parts).' }', 'optional' => false];

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

        $embeddedModelFqcns = array_values(array_unique(
            array_values($analysis->modelFqcns),
        ));

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
        if ($analysis->nestedResources !== []) {
            $result['embeddedResourceFqcns'] = array_values(array_unique(
                array_values($analysis->nestedResources),
            ));
        }

        return $result;
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
     * When neither the wrapped class nor the @mixin model declares it, two fallbacks run: a date-cast
     * receiver reflects the method on Carbon (guarded by carbonMethodReturnsUnimportableStringable()),
     * then knownMethodRule() covers can()/getKey()/count()/exists().
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

            if ($tsInfo['type'] !== '' && $tsInfo['type'] !== 'unknown') {
                return [...$tsInfo, 'optional' => false];
            }
        } elseif ($this->modelClass !== null && method_exists($this->modelClass, $methodName)) {
            // @mixin-style resources: `$this->resource->commentsCount()` lives on the model.
            /** @var class-string $modelClass */
            $modelClass = $this->modelClass;
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($modelClass), $methodName);

            if ($tsInfo['type'] !== '' && $tsInfo['type'] !== 'unknown') {
                return [...$tsInfo, 'optional' => false];
            }
        }

        // On a date-cast receiver (e.g. `created_at`) the method is a Carbon instance method reached
        // through the cast, not declared on the model — reflect it on Carbon/CarbonImmutable instead.
        if ($expr->var instanceof PropertyFetch && $expr->var->name instanceof Identifier) {
            $receiverAttr = $this->modelClass !== null
                ? resolve(ModelAttributeResolver::class)->getAttributes($this->modelClass)
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
     * Determine whether a Carbon(Immutable) method's declared return type is a concrete class that merely
     * implements `__toString()` (e.g. `diff()` → CarbonInterval) rather than a genuine `: string` return.
     *
     * LaravelTsPublish::toTsType()'s step 5b maps any non-Model Stringable class to a bare `string` with
     * no FQCN attached, so acceptReflectedTypeInfo() cannot tell it from a real string; this inspects the
     * raw reflected type first, mirroring that step's own condition. Carbon and CarbonImmutable are
     * allow-listed: their `__toString()` IS the canonical datetime string, unlike CarbonInterval's
     * human-readable formatting convenience.
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
        if ($this->modelClass === null) {
            return ['type' => 'unknown', 'modelFqcn' => null, 'morphFqcns' => []];
        }

        return resolve(ModelAttributeResolver::class)->resolveRelation($this->modelClass, $name);
    }

    /**
     * Analyze a method-call chain rooted at `$this->{manyRelation}` made of identity-preserving collection
     * ops (take, skip, filter, values, sortBy, ...) plus at most one `map()` or `pluck()`. A `map()` body
     * is analyzed with the relation's element model bound as $closureRelationModelClass.
     *
     * Returns null — deferring to the caller's unknown fallthrough — for any other op, a second map/pluck,
     * or an unresolvable body. That is why `$this->items->count()` still reaches knownMethodRule().
     *
     * @return ValueExpressionResult|null
     */
    protected function analyzeRelationCollectionChain(MethodCall $call): ?array
    {
        $identityOps = [
            'take', 'skip', 'filter', 'reject', 'values', 'unique',
            'sortBy', 'sortByDesc', 'slice', 'reverse', 'where', 'whereNotNull',
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
        $mapNode = null;
        $pluckNode = null;

        // A relation collection starts keyed 0..n-1; each op below says whether that still holds.
        $sequentialKeys = true;

        foreach (array_reverse($ops) as $op) {
            if (in_array($op['name'], $identityOps, true)) {
                $sequentialKeys = match ($op['name']) {
                    'values' => true,
                    'take' => $sequentialKeys && $this->isFrontAnchoredTake($op['node']),
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

            $previousContext = $this->closureRelationModelClass;
            $this->closureRelationModelClass = $elementModel;

            try {
                $pluckResult = $this->analyzeVariablePluckCall($pluckNode);
            } finally {
                $this->closureRelationModelClass = $previousContext;
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

        $previousContext = $this->closureRelationModelClass;
        $this->closureRelationModelClass = $elementModel;

        try {
            $bodyResult = $this->analyzeValueExpression($mapArg);
        } finally {
            $this->closureRelationModelClass = $previousContext;
        }

        if ($bodyResult['type'] === 'unknown') {
            return null;
        }

        // A map body that is entirely `EnumResource::make(...)` returns a singular 'enumFqcn', the contract
        // ResourceTransformer::rewriteEnumResourceTypes() reads to overwrite the property with a bare,
        // non-array `AsEnum<typeof X>`. Array-wrapping breaks that, so re-tag it as 'directEnumFqcn':
        // still imported, no longer eligible for the singular-type rewrite.
        if (isset($bodyResult['enumFqcn']) && ! isset($bodyResult['directEnumFqcn'])) {
            $bodyResult['directEnumFqcn'] = $bodyResult['enumFqcn'];
            unset($bodyResult['enumFqcn']);
        }

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
     * Add the object arm that json_encode emits for a gapped or reordered collection: `X[]` → `X[] | Record<string, X>`.
     */
    private function keyedObjectArm(string $arrayType): string
    {
        return $arrayType.' | Record<string, '.substr($arrayType, 0, -2).'>';
    }

    /**
     * Suffix a type with `[]`, parenthesizing a union first: TypeScript binds `[]` tighter than `|`,
     * so `string | null[]` parses as `string | (null[])`, not `(string | null)[]`.
     */
    private function arrayWrapType(string $type): string
    {
        return str_contains($type, '|') ? '('.$type.')[]' : $type.'[]';
    }

    /**
     * Late-stage rules for methods whose return type is fixed by Laravel convention: can()/cannot()/canAny()
     * → boolean, and count()/exists() → number/boolean on a `$this->{manyRelation}` receiver. Applied last,
     * after every more specific resolution path has had a chance to produce a real type.
     *
     * can() matches by name alone — every plausible receiver (an Authorizable user, the Gate facade, a
     * policy-backed model) returns bool. count()/exists() and getKey() are receiver-gated instead: those
     * names are commonly overloaded, and getKey()'s type depends on the receiver model's key type, so it
     * is limited to `$this->resource->getKey()`, which always means the outer resource's own model.
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

            if (! $isResourceReceiver || $this->modelClass === null) {
                return null;
            }

            $instance = resolve(ModelAttributeResolver::class)->getInstance($this->modelClass);

            $type = $instance?->getKeyType() === 'int' ? 'number' : 'string';

            return [...$this->unknownResult(), 'type' => $type, 'optional' => false];
        }

        if (! in_array($method, ['count', 'exists'], true)) {
            return null;
        }

        // Receiver must be $this->{manyRelation}
        if ($expr->var instanceof PropertyFetch
            && $this->isThisPropertyFetch($expr->var)
            && $expr->var->name instanceof Identifier
        ) {
            $relation = $this->resolveModelRelationTypeInfo($expr->var->name->toString());

            if (str_ends_with($relation['type'], '[]')) {
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
     * @return ValueExpressionResult
     */
    protected function analyzeStaticMethodOnResource(string $methodName): array
    {
        $result = $this->unknownResult();
        $wrappedClass = $this->resolveWrappedClass();

        if ($wrappedClass !== null && method_exists($wrappedClass, $methodName)) {
            /** @var class-string $wrappedClass */
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($wrappedClass), $methodName);

            if ($tsInfo['type'] !== '' && $tsInfo['type'] !== 'unknown') {
                return [...$tsInfo, 'optional' => false];
            }
        }

        if ($this->modelClass !== null && method_exists($this->modelClass, $methodName)) {
            /** @var class-string $modelClass */
            $modelClass = $this->modelClass;
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($modelClass), $methodName);

            if ($tsInfo['type'] !== '' && $tsInfo['type'] !== 'unknown') {
                return [...$tsInfo, 'optional' => false];
            }
        }

        return $result;
    }

    /**
     * Resolve a property access on a related model within a whenLoaded closure.
     *
     * Uses the same resolution chain as model attributes: accessor → cast → DB column type.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeRelatedModelProperty(string $propertyName): array
    {
        if ($this->closureRelationModelClass === null) {
            return $this->unknownResult(); // @codeCoverageIgnore
        }

        $tsInfo = resolve(ModelAttributeResolver::class)->resolveAttribute($this->closureRelationModelClass, $propertyName);

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
     * Resolve a method call (instance or static) on a related model within a whenLoaded closure.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeRelatedModelMethodCall(string $methodName): array
    {
        if ($this->closureRelationModelClass === null) {
            return $this->unknownResult(); // @codeCoverageIgnore
        }

        $tsInfo = resolve(ModelAttributeResolver::class)->resolveMethodReturnType($this->closureRelationModelClass, $methodName);

        if ($tsInfo['type'] !== '' && $tsInfo['type'] !== 'unknown') {
            return [...$tsInfo, 'optional' => false];
        }

        return $this->unknownResult(); // @codeCoverageIgnore
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
     * Checks the resource's own methods, then the wrapped class, then the backing model — covering
     * calls delegated via `__call` or `@mixin`.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeThisMethodCall(string $methodName): array
    {
        if ($this->resourceReflection->hasMethod($methodName)) {
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes($this->resourceReflection, $methodName);

            if ($tsInfo['type'] !== '' && $tsInfo['type'] !== 'unknown') {
                return [
                    ...$tsInfo,
                    'optional' => false,
                ];
            }
        }

        $wrappedClass = $this->resolveWrappedClass();

        if ($wrappedClass !== null && method_exists($wrappedClass, $methodName)) {
            /** @var class-string $wrappedClass */
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($wrappedClass), $methodName);

            if ($tsInfo['type'] !== '' && $tsInfo['type'] !== 'unknown') {
                return [
                    ...$tsInfo,
                    'optional' => false,
                ];
            }
        }

        if ($this->modelClass !== null && method_exists($this->modelClass, $methodName)) {
            /** @var class-string $modelClass */
            $modelClass = $this->modelClass;
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($modelClass), $methodName);

            if ($tsInfo['type'] !== '' && $tsInfo['type'] !== 'unknown') {
                return [
                    ...$tsInfo,
                    'optional' => false,
                ];
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
     * branch becomes optional, and differing types across branches are unioned.
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

        foreach ($analyses as $analysis) {
            foreach ($analysis->properties as $prop) {
                $propertyMap[$prop['name']][] = $prop;
            }

            $enumResources = [...$enumResources, ...$analysis->enumResources];
            $nestedResources = [...$nestedResources, ...$analysis->nestedResources];
            $directEnumFqcns = [...$directEnumFqcns, ...$analysis->directEnumFqcns];
            $modelFqcns = [...$modelFqcns, ...$analysis->modelFqcns];
            $multiEnumResourceFqcns = [...$multiEnumResourceFqcns, ...$analysis->multiEnumResourceFqcns];

            foreach ($analysis->customImports as $path => $names) { // @codeCoverageIgnoreStart
                $customImports[$path] = array_values(array_unique([
                    ...($customImports[$path] ?? []),
                    ...$names,
                ]));
            } // @codeCoverageIgnoreEnd
        }

        /** @var list<array{name: string, type: string, optional: bool, description: string}> */
        $properties = [];

        foreach ($propertyMap as $name => $entries) {
            $types = array_values(array_unique(array_column($entries, 'type')));
            $type = count($types) === 1 ? $types[0] : implode(' | ', $types);

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
            multiEnumResourceFqcns: $multiEnumResourceFqcns,
        );
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
        return $this->resolveClassOnProperty($this->resourceReflection) ?? $this->instanceOfWrappedClass;
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

        return $result;
    }

    /**
     * Analyze `$variable->map(fn (TypedClass $item) => [...])` using the closure's typed first parameter
     * as the element model, wrapping the body result as `elementType[]`.
     *
     * Returns null when there is no typed Model parameter or the body resolves to unknown, so the
     * caller falls through to the generic method handler.
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

        // Require a named class type hint (already FQCN-resolved by NameResolver)
        if (! ($firstParam->type instanceof Name)) {
            return null;
        }

        $paramClass = $firstParam->type->toString();

        if (! class_exists($paramClass) || ! is_a($paramClass, Model::class, true)) {
            return null;
        }

        /** @var class-string<Model> $paramClass */
        $previousRelationModel = $this->closureRelationModelClass;
        $this->closureRelationModelClass = $paramClass;

        $returnExprs = $this->resolveClosureReturnExpressions($closureArg);

        $bodyResult = match (count($returnExprs)) {
            0 => null,
            1 => $this->analyzeValueExpression($returnExprs[0]),
            default => $this->analyzeClosureUnion($returnExprs),
        };

        $this->closureRelationModelClass = $previousRelationModel;

        if ($bodyResult === null || $bodyResult['type'] === 'unknown') {
            return null;
        }

        $bodyResult['type'] = $bodyResult['type'].'[]';
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
            $this->closureParamExprBindings[$firstParam->var->name] = $thisPropExpr;
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
        return ['type' => 'unknown', 'optional' => false];
    }
}
