<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\ResolvesModelRelationTypes;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\ResolvesRelatedModelTypes;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

/**
 * Expressions rooted at a bound variable rather than `$this` — `$item->name`, `$items->map(…)`,
 * `$items->pluck('x')`, `$item->method()`, and the bare variable itself resolved through the
 * scope's model, collection, closure-parameter and local-assignment binding maps.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class VariableHandler implements ExpressionHandler
{
    use InspectsAstNodes;
    use ResolvesModelRelationTypes;
    use ResolvesRelatedModelTypes;

    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [PropertyFetch::class, MethodCall::class, Variable::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        // $variable->property — resolve against the variable's own bound model (whenLoaded param,
        // chain map param, foreach value var), falling back to the ambient whenLoaded closure model.
        if ($expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && $expr->var->name !== 'this'
            && $expr->name instanceof Identifier
        ) {
            /** @var class-string<Model>|null $boundModel */
            $boundModel = $scope->varModelBindings[$expr->var->name] ?? $scope->closureRelationModelClass;

            if ($boundModel !== null) {
                return $this->analyzeRelatedModelProperty($expr->name->toString(), $scope, $boundModel);
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
            $mapResult = $this->analyzeVariableMapCall($expr, $scope, $engine);

            if ($mapResult !== null) {
                return $mapResult;
            }
        }

        // $variable->pluck('field') — resolve to an array of the field's type
        if ($scope->closureRelationModelClass !== null
            && $expr instanceof MethodCall
            && $expr->var instanceof Variable
            && is_string($expr->var->name)
            && $expr->var->name !== 'this'
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'pluck'
        ) {
            return $this->analyzeVariablePluckCall($expr, $scope);
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
            $boundModel = $scope->varModelBindings[$expr->var->name] ?? $scope->closureRelationModelClass;

            if ($boundModel !== null) {
                return $this->analyzeRelatedModelMethodCall($expr->name->toString(), $scope, $boundModel);
            }
        }

        // Bare variable bound to a model class (whenLoaded param, chain map param, foreach value var) —
        // resolves to the model's own type. Checked before closure-param/local-var expression bindings,
        // which resolve through a *different* expression rather than naming a model directly.
        if ($expr instanceof Variable && is_string($expr->name) && isset($scope->varModelBindings[$expr->name])) {
            $modelFqcn = $scope->varModelBindings[$expr->name];

            return [
                ...ValueResult::unknown(),
                'type' => class_basename($modelFqcn),
                'optional' => false,
                'modelFqcn' => $modelFqcn,
            ];
        }

        // Bare variable bound to a whole relation collection (to-many whenLoaded param) — resolves to
        // the collection type, e.g. `User[]`, never the singular element model.
        if ($expr instanceof Variable && is_string($expr->name) && isset($scope->varCollectionBindings[$expr->name])) {
            $binding = $scope->varCollectionBindings[$expr->name];

            return [
                ...ValueResult::unknown(),
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
            $boundExpr = $scope->closureParamExprBindings[$expr->name]
                ?? $scope->localVarBindings[$expr->name]
                ?? null;

            if ($boundExpr !== null && ! isset($scope->resolvingLocalVars[$expr->name])) {
                $scope->resolvingLocalVars[$expr->name] = true;

                try {
                    return $engine->resolve($boundExpr);
                } finally {
                    unset($scope->resolvingLocalVars[$expr->name]);
                }
            }
        }

        return null;
    }

    /**
     * Analyze `$variable->map(fn (TypedClass $item) => [...])` using the closure's typed first param
     * as the element model, wrapping the body result as `elementType[]`.
     *
     * Returns null when there's no typed Model parameter, deferring to the generic method handler.
     *
     * @return ValueExpressionResult|null
     */
    private function analyzeVariableMapCall(MethodCall $call, AnalysisScope $scope, ExpressionEngine $engine): ?array
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
            : $this->resolveMapProxyElementModel($call->var, $scope);

        if ($paramClass === null || ! class_exists($paramClass) || ! is_a($paramClass, Model::class, true)) {
            return null;
        }

        /** @var class-string<Model> $paramClass */
        $previousRelationModel = $scope->closureRelationModelClass;
        $scope->closureRelationModelClass = $paramClass;

        $returnExprs = $this->resolveClosureReturnExpressions($closureArg);

        $bodyResult = match (count($returnExprs)) {
            0 => null,
            1 => $engine->resolve($returnExprs[0]),
            default => ValueResult::analyzeClosureUnion($returnExprs, $engine),
        };

        $scope->closureRelationModelClass = $previousRelationModel;

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
     * Mirrors RelationCollectionChainHandler::analyzeVariablePluckCall(); see that copy's note.
     *
     * @return ValueExpressionResult
     */
    private function analyzeVariablePluckCall(MethodCall $call, AnalysisScope $scope): array
    {
        $args = $call->getArgs();

        if (count($args) >= 1 && $args[0]->value instanceof String_) {
            $fieldName = $args[0]->value->value;
            $info = $this->analyzeRelatedModelProperty($fieldName, $scope);

            if ($info['type'] !== 'unknown') {
                $info['type'] = $this->arrayWrapType($info['type']);
                $info['optional'] = false;

                return $info;
            }
        }

        return ['type' => 'unknown[]', 'optional' => false];
    }

    /**
     * Resolve the element model behind a `->map` proxy receiver: a whenLoaded to-many closure
     * parameter, or `$this->relation` itself. A singular relation's bound variable is not a
     * collection and must not match, so it returns null rather than guessing a shape.
     *
     * Mirrors RelationFilterHandler::resolveMapProxyElementModel(); see that copy's note.
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
     * Suffix a type with `[]`, parenthesizing a union or intersection first: TypeScript binds `[]`
     * tighter than both, so `A & B[]` parses as `A & (B[])`, not `(A & B)[]`.
     *
     * Mirrors RelationCollectionChainHandler::arrayWrapType(); see that copy's note.
     */
    private function arrayWrapType(string $type): string
    {
        return str_contains($type, '|') || str_contains($type, '&') ? '('.$type.')[]' : $type.'[]';
    }
}
