<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;

/**
 * Closures and arrow functions used as a property value — resolves the body's return type(s),
 * falling back to the declared return-type annotation when the body itself resolves to `unknown`.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 *
 * @phpstan-type ClosureAnnotationResult = array{
 *      type: string,
 *      directEnumFqcn?: class-string,
 *      modelFqcn?: class-string
 * }
 */
final class ClosureHandler implements ExpressionHandler
{
    use InspectsAstNodes;

    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [ArrowFunction::class, ClosureExpr::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        // Closures / arrow functions — body analysis first, return-type annotation as the fallback.
        $closureReturns = $this->resolveClosureReturnExpressions($expr);

        if ($closureReturns !== []) {
            // A param merely shadows a same-named outer local for this body — it must not resolve
            // through the outer binding just because no scoped binding (e.g. whenLoaded) claimed it.
            $previousLocalVarBindings = $scope->localVarBindings;

            if ($expr instanceof ArrowFunction || $expr instanceof ClosureExpr) {
                foreach ($expr->params as $param) {
                    if ($param->var instanceof Variable && is_string($param->var->name)) {
                        unset($scope->localVarBindings[$param->var->name]);
                    }
                }
            }

            try {
                $bodyResult = count($closureReturns) === 1
                    ? $engine->resolve($closureReturns[0])
                    : $this->analyzeClosureUnion($closureReturns, $engine);

                if ($bodyResult['type'] !== 'unknown') {
                    return $bodyResult;
                }

                $annotationResult = $this->resolveClosureAstReturnType($expr);

                if ($annotationResult !== null) {
                    return [...$annotationResult, 'optional' => false];
                }

                return $bodyResult;
            } finally {
                $scope->localVarBindings = $previousLocalVarBindings;
            }
        }

        return null;
    }

    /**
     * Merge multiple closure return expressions into a single union-typed ValueExpressionResult.
     *
     * Null returns (guard clauses) contribute `null` to the union instead of a full object shape;
     * duplicate types are removed and import metadata is collected from all branches.
     *
     * Public: the analyzer's own ternary and untyped-map-closure guards call back into this until
     * their slices extract, since both need the same guard-clause/dedup handling as a closure body.
     *
     * @param  list<Expr>  $returns
     * @return ValueExpressionResult
     */
    public function analyzeClosureUnion(array $returns, ExpressionEngine $engine): array
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

            $inner = $engine->resolve($returnExpr);

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
            return ValueResult::unknown(); // @codeCoverageIgnore
        }

        return ValueResult::mergeUnion($types, $branchResults);
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
}
