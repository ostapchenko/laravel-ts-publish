<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Analyzers\Inertia\NativeInertiaPageAnalyzer;
use AbeTwoThree\LaravelTsPublish\Tests\Unit\Analyzers\Inertia\Fixtures\ControllerWithDelegatedProps;
use Workbench\App\Http\Controllers\InertiaSingleResourceController;
use Workbench\App\Http\Controllers\InertiaTsCastsController;
use Workbench\App\Http\Controllers\InertiaUserShapesController;
use Workbench\App\Http\Controllers\PostInertiaController;
use Workbench\App\Http\Resources\WarehouseResource;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/** Analyze one `Controller@action` through the native analyzer. */
function nativePageData(string $uses): ?array
{
    return new NativeInertiaPageAnalyzer()->analyze(['uses' => $uses]);
}

it('types Eloquent finders, collections and paginators from the model their chain is rooted at', function () {
    $show = nativePageData(InertiaUserShapesController::class.'@show');
    $index = nativePageData(InertiaUserShapesController::class.'@index');
    $paginated = nativePageData(PostInertiaController::class.'@index');

    expect($show['pageType'])->toBe('Inertia.SharedData & { post: Post, draft: Post | null }')
        ->and($show['classFqcns'])->toBe([Post::class])
        ->and($index['pageType'])->toBe('Inertia.SharedData & { users: User[], posts: Post[], page: number }')
        ->and($index['classFqcns'])->toBe([User::class, Post::class])
        ->and($paginated['pageType'])->toBe('Inertia.SharedData & { posts: LengthAwarePaginator<Post> }')
        ->and($paginated['externalImports'])->toBe(['@tolki/types' => ['LengthAwarePaginator']]);
});

it('marks the lazy Inertia wrappers optional and keeps the wrapped value type', function () {
    $data = nativePageData(InertiaUserShapesController::class.'@deferred');

    expect($data['pageType'])->toBe('Inertia.SharedData & { comments?: Comment[], tally?: number }')
        ->and($data['classFqcns'])->toBe([Comment::class]);
});

it('reads compact() keys and array_merge() props as the array literal each is equivalent to', function () {
    $compacted = nativePageData(InertiaUserShapesController::class.'@compacted');
    $merged = nativePageData(InertiaUserShapesController::class.'@merged');

    expect($compacted['pageType'])->toBe('Inertia.SharedData & { post: Post, comments: Comment[] }')
        ->and($merged['pageType'])->toBe('Inertia.SharedData & { title: string, extra: boolean }');
});

it('merges a ternary-assigned props array so a key only one arm sets is optional', function () {
    $data = nativePageData(InertiaUserShapesController::class.'@toggled');

    expect($data['component'])->toBe('UserShapes/Toggled')
        ->and($data['pageType'])->toBe('Inertia.SharedData & { post: Post | null, views?: number }')
        ->and($data['classFqcns'])->toBe([Post::class]);
});

// Both branches name Post, so a merge that appended per occurrence instead of per key would emit
// the FQCN twice and the transformer would import it twice.
it('merges two renders of one component into a single page type and one import per class', function () {
    $data = nativePageData(InertiaUserShapesController::class.'@branched');

    expect($data['component'])->toBe('UserShapes/Branched')
        ->and($data['pageType'])->toBe('Inertia.SharedData & { post: Post | null, detail?: string }')
        ->and($data['classFqcns'])->toBe([Post::class]);
});

it('falls back to bare SharedData for a render with no props', function () {
    $data = nativePageData(PostInertiaController::class.'@show');
    $none = nativePageData(PostInertiaController::class.'@create');

    expect($data['pageType'])->toBe('Inertia.SharedData & { post: Post }')
        ->and($none['pageType'])->toBe('Inertia.SharedData')
        ->and($none['classFqcns'])->toBe([]);
});

it('types the route-bound model parameter and the injected service call', function () {
    $data = nativePageData(InertiaUserShapesController::class.'@bound');

    expect($data['pageType'])->toBe('Inertia.SharedData & { post: Post, stats: { views: number; likes: number } }');
});

it('types a resource collection from what it wraps', function () {
    $paginated = nativePageData(InertiaSingleResourceController::class.'@resourcePaginatedCollection');
    $anonymous = nativePageData(InertiaSingleResourceController::class.'@resourceAnonymousCollection');

    expect($paginated['pageType'])->toBe('Inertia.SharedData & { warehouses: JsonResourcePaginator<WarehouseResource> }')
        ->and($paginated['classFqcns'])->toBe([WarehouseResource::class])
        ->and($paginated['externalImports'])->toBe(['@tolki/types' => ['JsonResourcePaginator']])
        ->and($anonymous['pageType'])->toBe(
            'Inertia.SharedData & { warehouse_get: AnonymousResourceCollection<WarehouseResource>, warehouse_all: AnonymousResourceCollection<WarehouseResource> }'
        );
});

it('applies #[TsCasts] overrides and their imports', function () {
    $data = nativePageData(InertiaTsCastsController::class.'@index');

    expect($data['pageType'])->toBe('Inertia.SharedData & { count: string, meta: PageMeta }')
        ->and($data['externalImports'])->toBe(['@workbench/types' => ['PageMeta']]);
});

it('types props delegated to a collaborator, and reads both inertia() helper forms', function () {
    $delegated = nativePageData(ControllerWithDelegatedProps::class.'@index');
    $helper = nativePageData(ControllerWithDelegatedProps::class.'@helper');
    $chain = nativePageData(ControllerWithDelegatedProps::class.'@helperChain');

    expect($delegated['component'])->toBe('Dashboard/Delegated')
        ->and($delegated['pageType'])->toBe('Inertia.SharedData & { heading: string, total: number }')
        ->and($helper['component'])->toBe('Dashboard/Helper')
        ->and($helper['pageType'])->toBe('Inertia.SharedData & { label: string }')
        ->and($chain['component'])->toBe('Dashboard/HelperChain')
        ->and($chain['pageType'])->toBe('Inertia.SharedData & { label: string }');
});

it('returns null for an action that renders no Inertia response', function () {
    expect(nativePageData(PostInertiaController::class.'@destroy'))->toBeNull()
        ->and(nativePageData(PostInertiaController::class.'@missingMethod'))->toBeNull()
        ->and(nativePageData('Closure'))->toBeNull();
});

// A class basename colliding with a TypeScript utility head must not be kept alive by the
// `Record<string, X>` a preserve-keys collection renders — that would import a type the page
// never names, which is the TS2304 shape the token gate exists to catch.
it('does not treat a TypeScript utility head as a class the page type names', function () {
    $analyzer = new class extends NativeInertiaPageAnalyzer
    {
        public function expose(string $pageType, string $name): bool
        {
            return $this->typeSpells($pageType, $name);
        }
    };

    $preserveKeys = "Inertia.SharedData & { teams: Omit<JsonResourcePaginator<TeamResource>, 'data'> & { data: Record<string, TeamResource> } }";

    expect($analyzer->expose($preserveKeys, 'Record'))->toBeFalse()
        ->and($analyzer->expose($preserveKeys, 'Omit'))->toBeFalse()
        ->and($analyzer->expose($preserveKeys, 'TeamResource'))->toBeTrue()
        ->and($analyzer->expose('Inertia.SharedData & { audit: Record }', 'Record'))->toBeTrue();
});
