<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Ast\AstParser;
use AbeTwoThree\LaravelTsPublish\Ast\CallMatcher;
use AbeTwoThree\LaravelTsPublish\Ast\InertiaRenderLocator;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

const USERS_CONTROLLER_SOURCE = <<<'PHP'
<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class UsersController
{
    public function index()
    {
        return Inertia::render('Users/Index', ['users' => $users]);
    }

    public function edit()
    {
        return view('users.edit');
    }

    public function show()
    {
        return Inertia::render('Users/Show', $this->service->find());
    }

    public function branched($flag)
    {
        if ($flag) {
            return inertia('Users/Branched', ['a' => 1]);
        }

        return inertia()->render('Users/Branched', ['b' => 2]);
    }
}
PHP;

function findControllerMethod(string $name): ClassMethod
{
    $stmts = new AstParser()->parseSource(USERS_CONTROLLER_SOURCE);

    /** @var ClassMethod|null $method */
    $method = (new NodeFinder)->findFirst(
        $stmts,
        fn (Node $node): bool => $node instanceof ClassMethod && $node->name->toString() === $name,
    );

    expect($method)->toBeInstanceOf(ClassMethod::class);

    return $method;
}

it('finds the render call, its component, and its array props for an inline-array action', function () {
    $locator = new InertiaRenderLocator(new CallMatcher);

    $render = $locator->findRenderCall(findControllerMethod('index'));

    expect($render)->not->toBeNull()
        ->and($locator->componentName($render))->toBe('Users/Index')
        ->and($locator->propsArray($render))->not->toBeNull()
        ->and($locator->propsArray($render)->items)->toHaveCount(1);
});

it('finds no render call for a method with no render call, so every downstream accessor is unreachable', function () {
    // componentName()/propsArg()/propsArray() all require a StaticCall argument; every call site
    // (Task 6) null-checks findRenderCall() first, exactly like the four duplicated originals do.
    $locator = new InertiaRenderLocator(new CallMatcher);

    expect($locator->findRenderCall(findControllerMethod('edit')))->toBeNull();
});

it('keeps propsArg and propsArray distinct when the second argument is a method call, not an array', function () {
    $locator = new InertiaRenderLocator(new CallMatcher);

    $render = $locator->findRenderCall(findControllerMethod('show'));

    expect($render)->not->toBeNull()
        ->and($locator->propsArg($render))->not->toBeNull()
        ->and($locator->propsArray($render))->toBeNull();
});

it('normalizes every render call form in a method into name/props pairs, in source order', function () {
    $locator = new InertiaRenderLocator(new CallMatcher);

    $calls = $locator->findRenderCalls(findControllerMethod('branched'));

    expect($calls)->toHaveCount(2)
        ->and($calls[0]->nameArg)->toBeInstanceOf(String_::class)
        ->and($calls[0]->nameArg->value)->toBe('Users/Branched')
        ->and($calls[0]->propsArg)->toBeInstanceOf(Array_::class)
        ->and($calls[1]->nameArg->value)->toBe('Users/Branched')
        ->and($calls[1]->propsArg)->toBeInstanceOf(Array_::class);
});

it('reports a render call with no props argument as a null propsArg, and finds none where there is none', function () {
    $locator = new InertiaRenderLocator(new CallMatcher);

    $calls = $locator->findRenderCalls(findControllerMethod('show'));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->propsArg)->not->toBeInstanceOf(Array_::class)
        ->and($locator->findRenderCalls(findControllerMethod('edit')))->toBe([]);
});
