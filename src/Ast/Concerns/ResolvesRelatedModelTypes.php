<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Concerns;

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\ValueResult;
use AbeTwoThree\LaravelTsPublish\ModelAttributeResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolve a property/method access against a related model — an explicitly bound one, or by
 * default the ambient whenLoaded closure's related model.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 * @phpstan-import-type TypeScriptTypeInfo from \AbeTwoThree\LaravelTsPublish\LaravelTsPublish
 */
trait ResolvesRelatedModelTypes
{
    /**
     * Resolve a property access on a related model — an explicitly bound model, or by default the
     * ambient whenLoaded closure's related model.
     *
     * Uses the same resolution chain as model attributes: accessor → cast → DB column type.
     *
     * @param  class-string<Model>|null  $modelFqcn
     * @return ValueExpressionResult
     */
    protected function analyzeRelatedModelProperty(string $propertyName, AnalysisScope $scope, ?string $modelFqcn = null): array
    {
        $modelFqcn ??= $scope->closureRelationModelClass;

        if ($modelFqcn === null) {
            return ValueResult::unknown(); // @codeCoverageIgnore
        }

        $tsInfo = resolve(ModelAttributeResolver::class)->resolveAttribute($modelFqcn, $propertyName);

        if ($tsInfo['type'] === 'unknown') {
            return ValueResult::unknown();
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
    protected function analyzeRelatedModelMethodCall(string $methodName, AnalysisScope $scope, ?string $modelFqcn = null): array
    {
        $modelFqcn ??= $scope->closureRelationModelClass;

        if ($modelFqcn === null) {
            return ValueResult::unknown(); // @codeCoverageIgnore
        }

        $tsInfo = resolve(ModelAttributeResolver::class)->resolveMethodReturnType($modelFqcn, $methodName);

        return $this->acceptReflectedTypeInfo($tsInfo) ?? ValueResult::unknown();
    }

    /**
     * Accept a reflected TypeScriptTypeInfo as a ValueExpressionResult, or null when any referenced
     * type can't be imported.
     *
     * A non-Model class token has no published file to import, so its presence rejects the whole result.
     *
     * Duplicated from ResourceAstAnalyzer — a trait can't call the analyzer's protected helper.
     * Task 19 (Slice S6) moves it to ReflectedTypeAcceptor::accept() and repoints this trait too.
     * Unreachable from ResourceAstAnalyzer itself: its own `protected` copy always wins there.
     *
     * @param  TypeScriptTypeInfo  $tsInfo
     * @return ValueExpressionResult|null
     */
    private function acceptReflectedTypeInfo(array $tsInfo): ?array
    {
        if (in_array($tsInfo['type'], ['unknown', 'unknown | null', 'void', 'never', ''], true)) {
            return null;
        }

        foreach ($tsInfo['classFqcns'] as $fqcn) {
            if (! is_a($fqcn, Model::class, true)) {
                return null;
            }
        }

        $result = [...ValueResult::unknown(), 'type' => $tsInfo['type'], 'optional' => false];

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
}
