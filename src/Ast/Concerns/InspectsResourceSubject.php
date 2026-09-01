<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Concerns;

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Concerns\ResolvesClassNames;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Facts about the subject class under analysis that handlers read straight off the scope:
 * the class its `$resource` wraps, and whether it is a ResourceCollection.
 *
 * The single scope-flavoured home for both — StaticCallHandler, SubjectMethodTypeResolver and
 * ThisPropertyHandler each carried their own copy before this trait.
 */
trait InspectsResourceSubject
{
    use ResolvesClassNames;

    /**
     * Resolve the wrapped class for this resource, falling back to the instanceof guard clause hint.
     *
     * @return class-string|null
     */
    protected function resolveWrappedClass(AnalysisScope $scope): ?string
    {
        return $this->resolveClassOnProperty($scope->subjectReflection) ?? $scope->instanceOfWrappedClass;
    }

    /**
     * Determine whether the analyzed resource is a ResourceCollection subclass.
     *
     * Mirrors ResourceAstAnalyzer::isResourceCollection(); duplicated for $scope, not $this->scope —
     * still used there by analyze() and knownMethodRule(), so it stays defined on the analyzer too.
     */
    protected function isResourceCollection(AnalysisScope $scope): bool
    {
        return $scope->subjectReflection->isSubclassOf(ResourceCollection::class);
    }
}
