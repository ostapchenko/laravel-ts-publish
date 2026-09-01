<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Analyzers\ResourceAstAnalyzer;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\KnownFunctionCallHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\KnownMethodRuleHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\StaticCallHandler;
use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;
use AbeTwoThree\LaravelTsPublish\Tests\Unit\Analyzers\Inertia\Fixtures\StarterKit\StarterKitMiddleware;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Workbench\App\Http\Resources\CommentResource;
use Workbench\App\Models\Comment;
use Workbench\App\Models\User;

/** An engine no rule in this file may call: every rule here reads the receiver, never a sub-expression. */
function requestRuleEngine(): ExpressionEngine
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

function requestRuleScope(bool $seeded = true): AnalysisScope
{
    $scope = new AnalysisScope(new ReflectionClass(stdClass::class));

    if ($seeded) {
        $scope->requestVarNames = ['request' => true];
    }

    return $scope;
}

function requestCall(string $method): MethodCall
{
    return new MethodCall(new Variable('request'), $method, [new Arg(new String_('key'))]);
}

it('types a Request method call against the rule table', function (string $method, string $type) {
    expect((new KnownMethodRuleHandler)->resolve(requestCall($method), requestRuleScope(), requestRuleEngine()))
        ->toBe(['type' => $type, 'optional' => false]);
})->with([
    ['url', 'string'],
    ['fullUrl', 'string'],
    ['path', 'string'],
    ['string', 'string'],
    ['integer', 'number'],
    ['boolean', 'boolean'],
    ['hasCookie', 'boolean'],
    ['cookie', 'string | null'],
]);

it('types $request->user() through the auth provider model', function () {
    expect((new KnownMethodRuleHandler)->resolve(requestCall('user'), requestRuleScope(), requestRuleEngine()))
        ->toBe(['type' => 'User | null', 'optional' => false, 'modelFqcn' => User::class]);
});

it('declines the whole rule table when the receiver is not a known Request variable', function () {
    expect((new KnownMethodRuleHandler)->resolve(requestCall('url'), requestRuleScope(seeded: false), requestRuleEngine()))
        ->toBeNull()
        ->and((new KnownMethodRuleHandler)->resolve(requestCall('user'), requestRuleScope(seeded: false), requestRuleEngine()))
        ->toBeNull();
});

it('declines a Request method outside the table', function () {
    expect((new KnownMethodRuleHandler)->resolve(requestCall('session'), requestRuleScope(), requestRuleEngine()))
        ->toBeNull();
});

it('keeps the Request rules off a resource, whose toArray() also takes a Request', function () {
    // A resource's committed output was inferred without these rules; seeding them there would move
    // it, so ResourceAstAnalyzer deliberately leaves requestVarNames empty for a JsonResource.
    $analyzer = new ResourceAstAnalyzer(new ReflectionClass(CommentResource::class), Comment::class);

    expect($analyzer->resolve(requestCall('url')))->toBe(['type' => 'unknown', 'optional' => false]);
});

it('seeds requestVarNames for a non-resource subject from the analyzed method signature', function () {
    $analyzer = new ResourceAstAnalyzer(new ReflectionClass(StarterKitMiddleware::class), null, 'share');

    expect($analyzer->resolve(requestCall('url')))->toBe(['type' => 'string', 'optional' => false]);
});

it('types auth()->user() and auth()->id()', function () {
    $call = fn (string $method): MethodCall => new MethodCall(new FuncCall(new Name('auth')), $method);

    expect((new KnownFunctionCallHandler)->resolve($call('user'), requestRuleScope(), requestRuleEngine()))
        ->toBe(['type' => 'User | null', 'optional' => false, 'modelFqcn' => User::class])
        ->and((new KnownFunctionCallHandler)->resolve($call('id'), requestRuleScope(), requestRuleEngine()))
        ->toBe(['type' => 'number | null', 'optional' => false]);
});

it('declines an unbound ->user() on any other receiver', function () {
    $expr = new MethodCall(new FuncCall(new Name('resolve')), 'user');

    expect((new KnownFunctionCallHandler)->resolve($expr, requestRuleScope(), requestRuleEngine()))->toBeNull();
});

it('types Auth::user() and Auth::id()', function () {
    $call = fn (string $method): StaticCall => new StaticCall(new Name('Auth'), $method);

    expect((new StaticCallHandler)->resolve($call('user'), requestRuleScope(), requestRuleEngine()))
        ->toBe(['type' => 'User | null', 'optional' => false, 'modelFqcn' => User::class])
        ->and((new StaticCallHandler)->resolve($call('id'), requestRuleScope(), requestRuleEngine()))
        ->toBe(['type' => 'number | null', 'optional' => false]);
});

it('types config() with a literal key from the live configuration value', function (string $key, mixed $value, string $type) {
    config()->set($key, $value);

    $expr = new FuncCall(new Name('config'), [new Arg(new String_($key))]);

    expect((new KnownFunctionCallHandler)->resolve($expr, requestRuleScope(), requestRuleEngine()))
        ->toBe(['type' => $type, 'optional' => false]);
})->with([
    ['ts-publish-probe.name', 'Laravel', 'string'],
    ['ts-publish-probe.debug', true, 'boolean'],
    ['ts-publish-probe.retries', 3, 'number'],
    ['ts-publish-probe.ratio', 1.5, 'number'],
    ['ts-publish-probe.hosts', ['a'], 'unknown[]'],
    ['ts-publish-probe.missing', null, 'null'],
]);

it('declines config() with a computed key', function () {
    $expr = new FuncCall(new Name('config'), [new Arg(new Variable('key'))]);

    expect((new KnownFunctionCallHandler)->resolve($expr, requestRuleScope(), requestRuleEngine()))->toBeNull();
});
