<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use PhpParser\Node\Expr\Variable;

it('constructs with the given subject reflection and model class', function () {
    $reflection = new ReflectionClass(stdClass::class);

    $scope = new AnalysisScope($reflection, 'App\\Models\\Post');

    expect($scope->subjectReflection)->toBe($reflection)
        ->and($scope->modelClass)->toBe('App\\Models\\Post')
        ->and($scope->instanceOfWrappedClass)->toBeNull()
        ->and($scope->closureRelationModelClass)->toBeNull()
        ->and($scope->closureParamExprBindings)->toBe([])
        ->and($scope->varModelBindings)->toBe([])
        ->and($scope->varCollectionBindings)->toBe([])
        ->and($scope->localVarBindings)->toBe([])
        ->and($scope->resolvingLocalVars)->toBe([])
        ->and($scope->visitedSpreadMethods)->toBe([]);
});

it('defaults modelClass to null when omitted', function () {
    $scope = new AnalysisScope(new ReflectionClass(stdClass::class));

    expect($scope->modelClass)->toBeNull();
});

it('round-trips all four binding maps through snapshotBindings()/restoreBindings()', function () {
    $scope = new AnalysisScope(new ReflectionClass(stdClass::class));

    $originalClosureParamExpr = new Variable('original');
    $originalLocalVar = new Variable('alsoOriginal');

    $scope->closureParamExprBindings = ['a' => $originalClosureParamExpr];
    $scope->varModelBindings = ['b' => 'App\\Models\\User'];
    $scope->varCollectionBindings = ['c' => ['type' => 'User[]', 'modelFqcn' => 'App\\Models\\User']];
    $scope->localVarBindings = ['d' => $originalLocalVar];

    $snapshot = $scope->snapshotBindings();

    $scope->closureParamExprBindings = ['a' => new Variable('mutated'), 'extra' => new Variable('extra')];
    $scope->varModelBindings = ['b' => 'App\\Models\\Order'];
    $scope->varCollectionBindings = ['c' => ['type' => 'Order[]', 'modelFqcn' => 'App\\Models\\Order']];
    $scope->localVarBindings = ['d' => new Variable('mutated')];

    $scope->restoreBindings($snapshot);

    expect($scope->closureParamExprBindings)->toBe(['a' => $originalClosureParamExpr])
        ->and($scope->varModelBindings)->toBe(['b' => 'App\\Models\\User'])
        ->and($scope->varCollectionBindings)->toBe(['c' => ['type' => 'User[]', 'modelFqcn' => 'App\\Models\\User']])
        ->and($scope->localVarBindings)->toBe(['d' => $originalLocalVar]);
});

it('does not resurrect a field the snapshot never captured', function () {
    $scope = new AnalysisScope(new ReflectionClass(stdClass::class));

    $scope->closureRelationModelClass = 'App\\Models\\User';
    $scope->resolvingLocalVars = ['x' => true];
    $scope->visitedSpreadMethods = ['someMethod' => true];
    $scope->instanceOfWrappedClass = 'App\\Foo';

    $snapshot = $scope->snapshotBindings();

    // Mutate every uncaptured field after the snapshot — restoreBindings() must leave these alone.
    $scope->closureRelationModelClass = 'App\\Models\\Order';
    $scope->resolvingLocalVars = ['y' => true];
    $scope->visitedSpreadMethods = ['otherMethod' => true];
    $scope->instanceOfWrappedClass = 'App\\Bar';

    $scope->restoreBindings($snapshot);

    expect($scope->closureRelationModelClass)->toBe('App\\Models\\Order')
        ->and($scope->resolvingLocalVars)->toBe(['y' => true])
        ->and($scope->visitedSpreadMethods)->toBe(['otherMethod' => true])
        ->and($scope->instanceOfWrappedClass)->toBe('App\\Bar');
});
