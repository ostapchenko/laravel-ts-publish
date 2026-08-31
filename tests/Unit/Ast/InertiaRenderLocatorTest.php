<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Ast\AstParser;
use AbeTwoThree\LaravelTsPublish\Ast\CallMatcher;
use AbeTwoThree\LaravelTsPublish\Ast\InertiaRenderLocator;
use PhpParser\Node;
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
