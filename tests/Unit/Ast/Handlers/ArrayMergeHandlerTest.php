<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Analyzers\ResourceAstAnalyzer;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ArrayMergeHandler;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;
use Workbench\App\Http\Resources\CommentResource;
use Workbench\App\Models\Comment;

/**
 * A real analyzer as the engine: the handler re-expresses its arguments as one array literal and
 * hands that back to the engine, so only a live dispatcher exercises the whole path.
 */
function arrayMergeTestEngine(): ResourceAstAnalyzer
{
    return new ResourceAstAnalyzer(new ReflectionClass(CommentResource::class), Comment::class);
}

/** @param  list<Arg|VariadicPlaceholder>  $args */
function arrayMergeCall(array $args): FuncCall
{
    return new FuncCall(new Name('array_merge'), $args);
}

function arrayMergeLiteral(string $key, int|string $value): Arg
{
    $node = is_int($value) ? new Int_($value) : new String_($value);

    return new Arg(new Array_([new ArrayItem($node, new String_($key))]));
}

it('merges array literal arguments into one inline object', function () {
    $expr = arrayMergeCall([arrayMergeLiteral('id', 1), arrayMergeLiteral('name', 'Ada')]);

    expect((new ArrayMergeHandler)->resolve($expr, new AnalysisScope(new ReflectionClass(stdClass::class)), arrayMergeTestEngine()))
        ->toBe(['type' => '{ id: number; name: string }', 'optional' => false]);
});

it('lets a later argument overwrite an earlier key, keeping its position', function () {
    $expr = arrayMergeCall([
        arrayMergeLiteral('id', 1),
        arrayMergeLiteral('name', 'Ada'),
        arrayMergeLiteral('id', 'first'),
    ]);

    // PHP's array_merge keeps the first occurrence's position and the last occurrence's value.
    expect((new ArrayMergeHandler)->resolve($expr, new AnalysisScope(new ReflectionClass(stdClass::class)), arrayMergeTestEngine())['type'])
        ->toBe('{ id: string; name: string }');
});

it('declines when an argument is not an array literal or a parent call', function () {
    $expr = arrayMergeCall([arrayMergeLiteral('id', 1), new Arg(new Variable('extra'))]);

    expect((new ArrayMergeHandler)->resolve($expr, new AnalysisScope(new ReflectionClass(stdClass::class)), arrayMergeTestEngine()))
        ->toBeNull();
});

it('accepts a parent:: argument', function () {
    $parentCall = new StaticCall(new Name('parent'), 'toArray', [new Arg(new Variable('request'))]);
    $expr = arrayMergeCall([new Arg($parentCall), arrayMergeLiteral('extra', 1)]);

    expect((new ArrayMergeHandler)->resolve($expr, new AnalysisScope(new ReflectionClass(stdClass::class)), arrayMergeTestEngine()))
        ->not->toBeNull();
});

it('declines another function and a first-class callable', function () {
    $scope = new AnalysisScope(new ReflectionClass(stdClass::class));
    $engine = arrayMergeTestEngine();

    expect((new ArrayMergeHandler)->resolve(new FuncCall(new Name('array_filter'), [arrayMergeLiteral('id', 1)]), $scope, $engine))
        ->toBeNull()
        ->and((new ArrayMergeHandler)->resolve(arrayMergeCall([new VariadicPlaceholder]), $scope, $engine))
        ->toBeNull();
});
