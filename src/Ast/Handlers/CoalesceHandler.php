<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Dtos\Contracts\Datable;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;

/**
 * Analyze a null-coalescing expression (`$left ?? $right`).
 *
 * Doesn't delegate to analyzeClosureUnion(): that would leave `null` in twice (`Order | null | Order`).
 * Only operands contributing a result member get their FQCN/import channels merged.
 *
 * @phpstan-import-type TypesImportMap from Datable
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class CoalesceHandler implements ExpressionHandler
{
    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [BinaryOp\Coalesce::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        if ($expr instanceof BinaryOp\Coalesce) {
            $leftResult = $engine->resolve($expr->left);
            $rightResult = $engine->resolve($expr->right);

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

        return null;
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
     * Fold union member types and their branch results into one ValueExpressionResult, carrying every
     * FQCN/import channel across so no emitted token loses its import.
     *
     * Shared by the ternary/closure union and by coalesce, which computes its own member list.
     *
     * Duplicated here for the same reason (a standalone handler can't call the analyzer's `protected`
     * helpers), and because the ValueResult::mergeUnion() target takes one param, not two, so S3 can't
     * shape it blind. Task 17 repoints this handler at ValueResult::mergeUnion() once it lands.
     *
     * @param  list<string>  $types
     * @param  list<ValueExpressionResult>  $branchResults
     * @return ValueExpressionResult
     */
    private function mergeUnionChannels(array $types, array $branchResults): array
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
}
