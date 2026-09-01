<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Analyzers\ResourceAnalysis;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ThisPropertyHandler;
use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use Workbench\App\Http\Resources\UserResource;
use Workbench\App\Models\User;

/**
 * An engine that fails the test if a handler calls back into it, proving the handler resolved or
 * declined without recursing into a sub-expression.
 */
function thisPropertyHandlerThrowingEngine(): ExpressionEngine
{
    return new class implements ExpressionEngine
    {
        public function resolve(Expr $expr): array
        {
            throw new RuntimeException('resolve() must not be called in this case');
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

/**
 * A stub engine resolving each distinct expression instance to its own canned result, keyed by
 * object identity — mirrors the convention used by the other handler test suites.
 */
final class ThisPropertyHandlerArmStubEngine implements ExpressionEngine
{
    /** @param list<array{0: Expr, 1: array<string, mixed>}> $arms */
    public function __construct(private array $arms) {}

    /** @return array<string, mixed> */
    public function resolve(Expr $expr): array
    {
        foreach ($this->arms as [$candidate, $result]) {
            if ($candidate === $expr) {
                return $result;
            }
        }

        throw new RuntimeException('Unexpected expression passed to ThisPropertyHandlerArmStubEngine');
    }

    public function spreadAnalysis(string $methodName): ?MethodAnalysis
    {
        throw new RuntimeException('spreadAnalysis() must not be called in this case');
    }

    public function returnArrayAnalysis(Array_ $array): MethodAnalysis
    {
        throw new RuntimeException('returnArrayAnalysis() must not be called in this case');
    }
}

it('resolves $this->id against a model-backed scope to the column type', function () {
    $expr = new PropertyFetch(new Variable('this'), 'id');
    $scope = new AnalysisScope(new ReflectionClass(UserResource::class), User::class);

    $result = (new ThisPropertyHandler)->resolve($expr, $scope, thisPropertyHandlerThrowingEngine());

    expect($result)->toBe(['type' => 'number', 'optional' => false]);
});

it('declines a property fetch not rooted at $this', function () {
    $expr = new PropertyFetch(new Variable('other'), 'id');
    $scope = new AnalysisScope(new ReflectionClass(UserResource::class), User::class);

    $result = (new ThisPropertyHandler)->resolve($expr, $scope, thisPropertyHandlerThrowingEngine());

    expect($result)->toBeNull();
});

it('extractPropertiesFromArray() resolves each keyed item through the engine, forcing $optional', function () {
    $nameValue = new Variable('nameExpr');

    $array = new Array_([
        new ArrayItem($nameValue, new String_('name')),
    ]);

    $engine = new ThisPropertyHandlerArmStubEngine([
        [$nameValue, ['type' => 'string', 'optional' => false]],
    ]);

    $analysis = (new ThisPropertyHandler)->extractPropertiesFromArray($array, $engine, optional: true);

    expect($analysis)->toBeInstanceOf(ResourceAnalysis::class)
        ->and($analysis->properties)->toBe([
            ['name' => 'name', 'type' => 'string', 'optional' => true, 'description' => ''],
        ]);
});

it('extractPropertiesFromArray() skips unkeyed items — it does not support spreads', function () {
    $array = new Array_([
        new ArrayItem(new Variable('spread'), null, unpack: true),
    ]);

    $analysis = (new ThisPropertyHandler)->extractPropertiesFromArray($array, thisPropertyHandlerThrowingEngine());

    expect($analysis->properties)->toBe([]);
});
