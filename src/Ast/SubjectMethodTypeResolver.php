<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast;

use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Concerns\ResolvesClassNames;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use ReflectionClass;

/**
 * Resolve a generic `$this->method()`/`$this::method()` call by reflecting its declared return
 * type: own methods, then the wrapped class, then the backing model, to cover calls delegated via
 * `__call`/`@mixin`.
 *
 * Shared because more than one guard reflects a subject method the same way: StaticCallHandler's
 * `$this::staticMethod()` branch and the generic `$this->method()` guard still on the analyzer.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class SubjectMethodTypeResolver
{
    use ResolvesClassNames;

    /**
     * @return ValueExpressionResult
     */
    public function resolve(AnalysisScope $scope, string $methodName): array
    {
        if ($scope->subjectReflection->hasMethod($methodName)) {
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes($scope->subjectReflection, $methodName);
            $accepted = resolve(ReflectedTypeAcceptor::class)->accept($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        }

        $wrappedClass = $this->resolveWrappedClass($scope);

        if ($wrappedClass !== null && method_exists($wrappedClass, $methodName)) {
            /** @var class-string $wrappedClass */
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($wrappedClass), $methodName);
            $accepted = resolve(ReflectedTypeAcceptor::class)->accept($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        }

        if ($scope->modelClass !== null && method_exists($scope->modelClass, $methodName)) {
            /** @var class-string $modelClass */
            $modelClass = $scope->modelClass;
            $tsInfo = LaravelTsPublish::methodOrDocblockReturnTypes(new ReflectionClass($modelClass), $methodName);
            $accepted = resolve(ReflectedTypeAcceptor::class)->accept($tsInfo);

            if ($accepted !== null) {
                return $accepted;
            }
        }

        return ValueResult::unknown();
    }

    /**
     * Mirrors ResourceAstAnalyzer::resolveWrappedClass(), duplicated for $scope, not $this->scope.
     */
    private function resolveWrappedClass(AnalysisScope $scope): ?string
    {
        return $this->resolveClassOnProperty($scope->subjectReflection) ?? $scope->instanceOfWrappedClass;
    }
}
