<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\ResolvesRelatedModelTypes;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use AbeTwoThree\LaravelTsPublish\ModelAttributeResolver;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;

/**
 * Laravel's conditional-property family: `when`, `unless`, `whenHas`, `whenNotNull`, `whenNull`,
 * `whenLoaded`, `whenCounted`, `whenAggregated`, `whenPivotLoaded`, `whenPivotLoadedAs`,
 * `whenAppended`, `whenExistsLoaded`, and `transform` — every one of them a `$this->` call.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class ConditionalMethodHandler implements ExpressionHandler
{
    use InspectsAstNodes;
    use ResolvesRelatedModelTypes;

    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [MethodCall::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        if ($this->isThisMethodCall($expr, 'when')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhen($expr, $scope, $engine);
        }

        // unless() delegates to when() unchanged: negating the condition changes which arm runs,
        // never what either arm's type is.
        if ($this->isThisMethodCall($expr, 'unless')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhen($expr, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'whenAppended')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenAppended($expr, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'whenExistsLoaded')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenExistsLoaded($expr, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'transform')) {
            /** @var MethodCall $expr */
            return $this->analyzeTransform($expr, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'whenHas')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenHas($expr, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'whenNotNull')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenNotNull($expr, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'whenNull')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenNull($expr, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'whenLoaded')) {
            /** @var MethodCall $expr */
            return $this->analyzeWhenLoaded($expr, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'whenCounted')) {
            /** @var MethodCall $expr */
            return $this->applyConditionalDefault(['type' => 'number', 'optional' => false], $expr, 2, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'whenAggregated')) {
            /** @var MethodCall $expr */
            return $this->applyConditionalDefault(['type' => 'number', 'optional' => false], $expr, 4, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'whenPivotLoaded')) {
            /** @var MethodCall $expr */
            return $this->applyConditionalDefault(ValueResult::unknown(), $expr, 2, $scope, $engine);
        }

        if ($this->isThisMethodCall($expr, 'whenPivotLoadedAs')) {
            /** @var MethodCall $expr */
            return $this->applyConditionalDefault(ValueResult::unknown(), $expr, 3, $scope, $engine);
        }

        return null;
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
    protected function applyConditionalDefault(
        array $value,
        MethodCall $call,
        int $index,
        AnalysisScope $scope,
        ExpressionEngine $engine,
        int $defaultArgCount = 0,
    ): array {
        if (! $this->hasExplicitDefaultArg($call, $index)) {
            return [...$value, 'optional' => true];
        }

        $defaultExpr = $call->getArgs()[$index]->value;

        // A default closure requiring more parameters than Laravel supplies it can never run, so its
        // arm is unreachable — the value arm stands alone, still required.
        if ($this->closureRequiresArguments($defaultExpr, $defaultArgCount)) {
            return [...$value, 'optional' => false];
        }

        $default = $engine->resolve($defaultExpr);

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

        return [...ValueResult::mergeUnion($members, [$value, $default]), 'optional' => false];
    }

    /**
     * Analyze $this->when(condition, value) — the value is the second arg.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhen(MethodCall $call, AnalysisScope $scope, ExpressionEngine $engine): array
    {
        $result = ValueResult::unknown();
        $args = $call->getArgs();

        if (count($args) >= 2) {
            $valueExpr = $args[1]->value;

            $previousBindings = $scope->closureParamExprBindings;
            $this->bindClosureParamsFromCondition($args[0]->value, $valueExpr, $scope);

            $inner = $engine->resolve($valueExpr);

            $scope->closureParamExprBindings = $previousBindings;

            return $this->applyConditionalDefault($inner, $call, 2, $scope, $engine);
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
    protected function analyzeWhenHas(MethodCall $call, AnalysisScope $scope, ExpressionEngine $engine): array
    {
        $result = ValueResult::unknown();
        $args = $call->getArgs();

        if (count($args) >= 1 && $args[0]->value instanceof String_) {
            $attrName = $args[0]->value->value;
            $info = $this->resolveModelAttributeTypeInfo($attrName, $scope);
            $result = ['type' => $info['type'], 'optional' => false];

            if ($info['enumFqcn'] !== null) {
                $wrapped = count($args) >= 2 && $this->isEnumResourceWrapCall($args[1]->value);
                $result[$wrapped ? 'enumFqcn' : 'directEnumFqcn'] = $info['enumFqcn'];
            }

            return $this->applyConditionalDefault($result, $call, 2, $scope, $engine);
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
    protected function analyzeWhenAppended(MethodCall $call, AnalysisScope $scope, ExpressionEngine $engine): array
    {
        $args = $call->getArgs();

        if ($args === [] || ! $args[0]->value instanceof String_) {
            return [...ValueResult::unknown(), 'optional' => true]; // @codeCoverageIgnore
        }

        $info = $this->resolveModelAttributeTypeInfo($args[0]->value->value, $scope);
        $result = ['type' => $info['type'], 'optional' => false];

        if ($info['enumFqcn'] !== null) {
            $wrapped = count($args) >= 2 && $this->isEnumResourceWrapCall($args[1]->value);
            $result[$wrapped ? 'enumFqcn' : 'directEnumFqcn'] = $info['enumFqcn'];
        }

        return $this->applyConditionalDefault($result, $call, 2, $scope, $engine);
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
    protected function analyzeWhenExistsLoaded(MethodCall $call, AnalysisScope $scope, ExpressionEngine $engine): array
    {
        $args = $call->getArgs();

        if ($args === [] || ! $args[0]->value instanceof String_) {
            return [...ValueResult::unknown(), 'optional' => true]; // @codeCoverageIgnore
        }

        return $this->applyConditionalDefault(['type' => 'boolean', 'optional' => false], $call, 2, $scope, $engine);
    }

    /**
     * Analyze $this->whenNotNull($value, $default) — the success arm returns $value, proven non-null.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenNotNull(MethodCall $call, AnalysisScope $scope, ExpressionEngine $engine): array
    {
        return $this->analyzeWhenPossiblyNull($call, stripNull: true, scope: $scope, engine: $engine);
    }

    /**
     * Analyze $this->whenNull($value, $default) — the success arm returns null, so only the default
     * carries a useful type.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenNull(MethodCall $call, AnalysisScope $scope, ExpressionEngine $engine): array
    {
        return $this->analyzeWhenPossiblyNull($call, stripNull: false, scope: $scope, engine: $engine);
    }

    /**
     * Shared logic for whenNotNull()/whenNull(): argument 0 is the value, argument 1 the optional
     * default. An explicit default makes the key required and unions its type into the result.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenPossiblyNull(MethodCall $call, bool $stripNull, AnalysisScope $scope, ExpressionEngine $engine): array
    {
        $args = $call->getArgs();

        if ($args === []) {
            return [...ValueResult::unknown(), 'optional' => true]; // @codeCoverageIgnore
        }

        $value = $engine->resolve($args[0]->value);

        if ($stripNull) {
            $value['type'] = $this->stripNullArm($value['type']);
        } else {
            $value['type'] = 'null';
        }

        return $this->applyConditionalDefault($value, $call, 1, $scope, $engine);
    }

    /**
     * Analyze $this->whenLoaded('relation') or $this->whenLoaded('relation', value, default).
     *
     * A single-model relation's closure param binds to the model; a to-many relation's binds to the
     * collection type instead, since the param holds the whole collection rather than one element.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeWhenLoaded(MethodCall $call, AnalysisScope $scope, ExpressionEngine $engine): array
    {
        $result = ValueResult::unknown();
        $args = $call->getArgs();

        if (count($args) >= 2) {
            // Resolve the related model so accesses on local variables inside the closure can be typed.
            $previousRelationModel = $scope->closureRelationModelClass;
            $previousVarModelBindings = $scope->varModelBindings;
            $previousVarCollectionBindings = $scope->varCollectionBindings;
            $relationInfo = null;

            if ($args[0]->value instanceof String_) {
                $relationInfo = $this->resolveModelRelationTypeInfo($args[0]->value->value, $scope);

                if ($relationInfo['modelFqcn'] !== null) {
                    $scope->closureRelationModelClass = $relationInfo['modelFqcn'];
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
                    $scope->varCollectionBindings[$paramName] = [
                        'type' => $relationInfo['type'],
                        'modelFqcn' => $relationInfo['modelFqcn'],
                    ];
                } else {
                    $scope->varModelBindings[$paramName] = $relationInfo['modelFqcn'];
                }
            }

            try {
                $inner = $engine->resolve($args[1]->value);
            } finally {
                $scope->closureRelationModelClass = $previousRelationModel;
                $scope->varModelBindings = $previousVarModelBindings;
                $scope->varCollectionBindings = $previousVarCollectionBindings;
            }

            return $this->applyConditionalDefault($inner, $call, 2, $scope, $engine);
        }

        if (count($args) >= 1 && $args[0]->value instanceof String_) {
            $relationName = $args[0]->value->value;
            $info = $this->resolveModelRelationTypeInfo($relationName, $scope);
            $result = ['type' => $info['type'], 'optional' => false];

            if ($info['modelFqcn'] !== null) {
                $result['modelFqcn'] = $info['modelFqcn'];
            }

            if ($info['morphFqcns'] !== []) {
                $result['embeddedModelFqcns'] = $info['morphFqcns'];
            }

            return $this->applyConditionalDefault($result, $call, 2, $scope, $engine);
        }

        return [...$result, 'optional' => true]; // @codeCoverageIgnore
    }

    /**
     * Analyze $this->transform($value, $callback, $default) — types from the callback's return, since
     * transform() invokes $callback with $value rather than passing $value through untouched.
     *
     * @return ValueExpressionResult
     */
    protected function analyzeTransform(MethodCall $call, AnalysisScope $scope, ExpressionEngine $engine): array
    {
        $result = ValueResult::unknown();
        $args = $call->getArgs();

        if (count($args) >= 2) {
            $valueExpr = $args[0]->value;
            $callbackExpr = $args[1]->value;

            $previousBindings = $scope->closureParamExprBindings;
            $this->bindClosureParamsFromCondition($valueExpr, $callbackExpr, $scope);

            $inner = $engine->resolve($callbackExpr);

            $scope->closureParamExprBindings = $previousBindings;

            // transform()'s default runs through the global transform() helper's $default($value) — one
            // argument — unlike the rest of the family's zero-argument value($default).
            return $this->applyConditionalDefault($inner, $call, 2, $scope, $engine, defaultArgCount: 1);
        }

        return [...$result, 'optional' => true]; // @codeCoverageIgnore
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
     * Bind a closure's first parameter to the `$this->propName` expression found in a `when()` condition,
     * so `EnumResource::make($status)` resolves as if it were `EnumResource::make($this->status)`.
     */
    private function bindClosureParamsFromCondition(Expr $condition, Expr $valueExpr, AnalysisScope $scope): void
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
            $scope->closureParamExprBindings[$firstParam->var->name] = $thisPropExpr;
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
     * Drop a top-level `| null` arm from a type string — a guarded success path proves it unreachable.
     * Nested null members (inside object shapes, generics, or array element types) are kept.
     *
     * Duplicated here — a standalone handler can't call the analyzer's `protected` helpers. Task 20
     * (Slice S7) moves stripNullArm() to its S7 home and repoints this handler there.
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

    /**
     * Resolve a `$this->{name}` property as a model relation, in ModelAttributeResolver::resolveRelation()'s
     * {type, modelFqcn, morphFqcns} shape — a to-many relation's type ends in '[]'.
     *
     * Mirrors ResourceAstAnalyzer's own override of this name; duplicated for $scope, not $this->scope.
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
}
