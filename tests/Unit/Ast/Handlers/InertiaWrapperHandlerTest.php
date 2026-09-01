<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\InertiaWrapperHandler;
use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;

/** An engine that answers every request with one canned scalar result. */
function inertiaWrapperEngine(): ExpressionEngine
{
    return new class implements ExpressionEngine
    {
        public function resolve(Expr $expr): array
        {
            return ['type' => 'string', 'optional' => false, 'modelFqcn' => 'Some\Model'];
        }

        public function spreadAnalysis(string $methodName): ?MethodAnalysis
        {
            throw new RuntimeException('spreadAnalysis() must not be called in this case');
        }

        public function returnArrayAnalysis(Array_ $array): MethodAnalysis
        {
            throw new RuntimeException('returnArrayAnalysis() must not be called in this case');
        }
    };
}

function inertiaWrapperCall(string $class, string $method): StaticCall
{
    return new StaticCall(new Name($class), $method, [new Arg(new String_('x'))]);
}

function inertiaWrapperScope(): AnalysisScope
{
    return new AnalysisScope(new ReflectionClass(stdClass::class));
}

it('resolves the wrapped value and keeps its channels', function (string $method) {
    expect((new InertiaWrapperHandler)->resolve(inertiaWrapperCall('Inertia', $method), inertiaWrapperScope(), inertiaWrapperEngine()))
        ->toBe(['type' => 'string', 'optional' => false, 'modelFqcn' => 'Some\Model']);
})->with(['always', 'merge', 'deepMerge']);

it('marks a lazily loaded wrapper optional', function (string $method) {
    expect((new InertiaWrapperHandler)->resolve(inertiaWrapperCall('Inertia', $method), inertiaWrapperScope(), inertiaWrapperEngine())['optional'])
        ->toBeTrue();
})->with(['defer', 'optional', 'lazy']);

it('declines another Inertia method, another class, and a first-class callable', function () {
    $scope = inertiaWrapperScope();
    $engine = inertiaWrapperEngine();

    expect((new InertiaWrapperHandler)->resolve(inertiaWrapperCall('Inertia', 'render'), $scope, $engine))->toBeNull()
        ->and((new InertiaWrapperHandler)->resolve(inertiaWrapperCall('Cache', 'defer'), $scope, $engine))->toBeNull()
        ->and((new InertiaWrapperHandler)->resolve(new StaticCall(new Name('Inertia'), 'defer', [new VariadicPlaceholder]), $scope, $engine))->toBeNull()
        ->and((new InertiaWrapperHandler)->resolve(new StaticCall(new Name('Inertia'), 'defer'), $scope, $engine))->toBeNull()
        ->and((new InertiaWrapperHandler)->resolve(new StaticCall(new Variable('inertia'), 'defer'), $scope, $engine))->toBeNull();
});
