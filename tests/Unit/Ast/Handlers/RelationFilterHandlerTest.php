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
use Workbench\App\Enums\Priority;
use Workbench\App\Enums\Status;
use Workbench\App\Enums\Visibility;
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

it('relation except() falls back to database columns only, matching Model::except() at runtime — RelationFilterHandler::resolveFilteredRelationType()', function () {
    // 'excerpt' is a pure accessor on Post, not a published column, so relationFilterModelReference()
    // declines (not every key is a column) and this reaches resolveFilteredRelationType()'s except
    // branch. HasAttributes::except() iterates getAttributes() only — never relations, and never a
    // get-only accessor — so 'excerpt' must never appear in the output even though it was named, while
    // 'created_at', a real column, is correctly dropped.
    $expr = new MethodCall(
        new PropertyFetch(new Variable('this'), 'post'),
        'except',
        [new Arg(new Array_([
            new ArrayItem(new String_('excerpt')),
            new ArrayItem(new String_('created_at')),
        ]))],
    );
    $scope = new AnalysisScope(new ReflectionClass(CommentResource::class), Comment::class);

    $result = (new RelationFilterHandler)->resolve($expr, $scope, relationFilterHandlerThrowingEngine());

    expect($result)->toBe([
        'type' => '{ id: number; title: string; content: string; user_id: number; status: StatusType; '
            .'published_at: string | null; metadata: unknown[] | null; rating: number | null; category: string; '
            .'options: Record<string, string> | null; deleted_at: string | null; updated_at: string | null; '
            .'category_id: number | null; visibility: VisibilityType | null; priority: PriorityType | null; '
            .'word_count: number | null; reading_time_minutes: number | null; featured_image_url: string | null; '
            .'is_pinned: boolean }',
        'optional' => false,
        'embeddedEnumFqcns' => [Status::class, Visibility::class, Priority::class],
        'embeddedModelFqcns' => [],
        'customImports' => [],
    ]);
});

it('relation only() still resolves a named accessor and a named relation, unlike except() — RelationFilterHandler::resolveFilteredRelationType()', function () {
    // 'title_display' (an accessor) and 'comments' (a relation) are both not published columns, so
    // relationFilterModelReference() declines and this reaches resolveFilteredRelationType()'s include
    // branch: HasAttributes::only() calls getAttribute() per named key, so an accessor comes back same
    // as a column, and a named relation resolves through ModelAttributeResolver::resolveRelation().
    $expr = new MethodCall(
        new PropertyFetch(new Variable('this'), 'post'),
        'only',
        [new Arg(new Array_([
            new ArrayItem(new String_('title')),
            new ArrayItem(new String_('title_display')),
            new ArrayItem(new String_('comments')),
        ]))],
    );
    $scope = new AnalysisScope(new ReflectionClass(CommentResource::class), Comment::class);

    $result = (new RelationFilterHandler)->resolve($expr, $scope, relationFilterHandlerThrowingEngine());

    expect($result)->toBe([
        'type' => '{ title: string; title_display: string | null; comments: Comment[] }',
        'optional' => false,
        'embeddedEnumFqcns' => [],
        'embeddedModelFqcns' => [Comment::class],
        'customImports' => [],
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
