<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\RelationFilterHandler;
use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use Workbench\App\Http\Resources\CommentResource;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;

/**
 * An engine that fails the test if a handler calls back into it, proving the handler resolved or
 * declined without recursing into a sub-expression.
 */
function relationFilterHandlerThrowingEngine(): ExpressionEngine
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

it('resolves $this->relation->only([...]) to a Pick<Model, ...> reference when every key is a column', function () {
    // Mirrors CommentResource::$post_limited — Comment::post() is a BelongsTo to Post, and 'id'/
    // 'title' are both plain published columns, so the relation-filter guard emits a Pick<> arm.
    $expr = new MethodCall(
        new PropertyFetch(new Variable('this'), 'post'),
        'only',
        [new Arg(new Array_([
            new ArrayItem(new String_('id')),
            new ArrayItem(new String_('title')),
        ]))],
    );
    $scope = new AnalysisScope(new ReflectionClass(CommentResource::class), Comment::class);

    $result = (new RelationFilterHandler)->resolve($expr, $scope, relationFilterHandlerThrowingEngine());

    expect($result)->toBe([
        'type' => "Pick<Post, 'id' | 'title'>",
        'optional' => false,
        'modelFqcn' => Post::class,
    ]);
});

it('declines a method call whose name is not only/except', function () {
    $expr = new MethodCall(
        new PropertyFetch(new Variable('this'), 'post'),
        'count',
    );
    $scope = new AnalysisScope(new ReflectionClass(CommentResource::class), Comment::class);

    $result = (new RelationFilterHandler)->resolve($expr, $scope, relationFilterHandlerThrowingEngine());

    expect($result)->toBeNull();
});
