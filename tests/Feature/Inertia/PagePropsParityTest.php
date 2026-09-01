<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Analyzers\Inertia\InertiaPageAnalyzer;
use AbeTwoThree\LaravelTsPublish\Analyzers\Inertia\NativeInertiaPageAnalyzer;
use AbeTwoThree\LaravelTsPublish\Support\TolkiTypes;
use Illuminate\Support\Facades\Route;

/**
 * Route action names, in registration order, for every route bound to a controller method.
 *
 * @return list<string>
 */
function parityActions(): array
{
    $actions = [];

    foreach (Route::getRoutes() as $route) {
        $uses = $route->getAction('uses');

        if (! is_string($uses) || ! str_contains($uses, '@')) {
            continue;
        }

        if (! in_array($uses, $actions, true)) {
            $actions[] = $uses;
        }
    }

    return $actions;
}

/**
 * Reduce an analyzer result to what a consumer sees: the component(s), the page type(s), and the
 * import tokens. A paginator reaches the transformer as a class FQCN from one analyzer and as an
 * external `@tolki/types` import from the other, so both are folded to one token here.
 *
 * @param  array<string, mixed>|null  $data  an InertiaPageData result, or null
 * @return array{component: mixed, pageType: mixed, imports: list<string>}|null
 */
function parityShape(?array $data): ?array
{
    if ($data === null) {
        return null;
    }

    $imports = [];

    foreach ($data['classFqcns'] as $fqcn) {
        $imports[] = isset(TolkiTypes::MAP[$fqcn]) ? '@tolki/types::'.TolkiTypes::MAP[$fqcn] : $fqcn;
    }

    foreach ($data['externalImports'] ?? [] as $path => $names) {
        foreach ($names as $name) {
            $imports[] = $path.'::'.$name;
        }
    }

    $imports = array_values(array_unique($imports));
    sort($imports);

    return ['component' => $data['component'], 'pageType' => $data['pageType'], 'imports' => $imports];
}

/**
 * Every difference the native analyzer is expected to produce, both sides spelled out, so the
 * config flip has nothing left to discover: finders and collections typed, wrappers/`compact()`/
 * ternary props read, `true`/`[]` widened, a dead import dropped, `;` between object members.
 *
 * @return array<string, array{surveyor: mixed, native: mixed}>
 */
function parityExpectedDifferences(): array
{
    return [
        'Workbench\App\Http\Controllers\InertiaController@dashboard' => [
            'surveyor' => [
                'component' => 'Dashboard',
                'pageType' => 'Inertia.SharedData & { stats: { users: number, posts: number, views: number }, recentActivity: [] }',
                'imports' => [],
            ],
            'native' => [
                'component' => 'Dashboard',
                'pageType' => 'Inertia.SharedData & { stats: { users: number; posts: number; views: number }, recentActivity: never[] }',
                'imports' => [],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaController@settings' => [
            'surveyor' => [
                'component' => 'Settings/General',
                'pageType' => 'Inertia.SharedData & { user: { name: string, email: string }, preferences: { theme: string, notifications: true } }',
                'imports' => [],
            ],
            'native' => [
                'component' => 'Settings/General',
                'pageType' => 'Inertia.SharedData & { user: { name: string; email: string }, preferences: { theme: string; notifications: boolean } }',
                'imports' => [],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaController@conditional' => [
            'surveyor' => [
                'component' => ['Conditional/Authenticated', 'Conditional/Guest'],
                'pageType' => [
                    'Inertia.SharedData & { user: unknown }',
                    'Inertia.SharedData & { message: string }',
                ],
                'imports' => [],
            ],
            'native' => [
                'component' => ['Conditional/Authenticated', 'Conditional/Guest'],
                'pageType' => [
                    'Inertia.SharedData & { user: User | null }',
                    'Inertia.SharedData & { message: string }',
                ],
                'imports' => ['Workbench\App\Models\User'],
            ],
        ],
        'Workbench\App\Http\Controllers\PostInertiaController@store' => [
            'surveyor' => [
                'component' => 'Posts/Show',
                'pageType' => 'Inertia.SharedData & { post: string }',
                'imports' => [],
            ],
            'native' => [
                'component' => 'Posts/Show',
                'pageType' => 'Inertia.SharedData & { post: Post }',
                'imports' => ['Workbench\App\Models\Post'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaSingleResourceController@resourcePaginatedCollection' => [
            'surveyor' => [
                'component' => 'Resource/PaginatedWarehouse',
                'pageType' => 'Inertia.SharedData & { warehouses: JsonResourcePaginator<WarehouseResource> }',
                'imports' => ['@tolki/types::AnonymousResourceCollection', '@tolki/types::JsonResourcePaginator', 'Workbench\App\Http\Resources\WarehouseResource'],
            ],
            'native' => [
                'component' => 'Resource/PaginatedWarehouse',
                'pageType' => 'Inertia.SharedData & { warehouses: JsonResourcePaginator<WarehouseResource> }',
                'imports' => ['@tolki/types::JsonResourcePaginator', 'Workbench\App\Http\Resources\WarehouseResource'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaResourceSharedTemplate@resourcePaginatedCollection' => [
            'surveyor' => [
                'component' => 'Resource/SharedTemplate',
                'pageType' => 'Inertia.SharedData & { warehouses: JsonResourcePaginator<WarehouseResource> }',
                'imports' => ['@tolki/types::AnonymousResourceCollection', '@tolki/types::JsonResourcePaginator', 'Workbench\App\Http\Resources\WarehouseResource'],
            ],
            'native' => [
                'component' => 'Resource/SharedTemplate',
                'pageType' => 'Inertia.SharedData & { warehouses: JsonResourcePaginator<WarehouseResource> }',
                'imports' => ['@tolki/types::JsonResourcePaginator', 'Workbench\App\Http\Resources\WarehouseResource'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaNamedCollectionsController@resourceAnonymousPaginated' => [
            'surveyor' => [
                'component' => 'Collections/ResourceAnonymous',
                'pageType' => 'Inertia.SharedData & { posts: JsonResourcePaginator<PostResource> }',
                'imports' => ['@tolki/types::AnonymousResourceCollection', '@tolki/types::JsonResourcePaginator', 'Workbench\App\Http\Resources\PostResource'],
            ],
            'native' => [
                'component' => 'Collections/ResourceAnonymous',
                'pageType' => 'Inertia.SharedData & { posts: JsonResourcePaginator<PostResource> }',
                'imports' => ['@tolki/types::JsonResourcePaginator', 'Workbench\App\Http\Resources\PostResource'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaPreserveKeysController@anonymousPaginated' => [
            'surveyor' => [
                'component' => 'PreserveKeys/AnonymousPaginated',
                'pageType' => 'Inertia.SharedData & { teams: Omit<JsonResourcePaginator<PreserveKeysTeamResource>, \'data\'> & { data: Record<string, PreserveKeysTeamResource> } }',
                'imports' => ['@tolki/types::AnonymousResourceCollection', '@tolki/types::JsonResourcePaginator', 'Workbench\App\Http\Resources\PreserveKeysTeamResource'],
            ],
            'native' => [
                'component' => 'PreserveKeys/AnonymousPaginated',
                'pageType' => 'Inertia.SharedData & { teams: Omit<JsonResourcePaginator<PreserveKeysTeamResource>, \'data\'> & { data: Record<string, PreserveKeysTeamResource> } }',
                'imports' => ['@tolki/types::JsonResourcePaginator', 'Workbench\App\Http\Resources\PreserveKeysTeamResource'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaPreserveKeysController@anonymousInlinePaginated' => [
            'surveyor' => [
                'component' => 'PreserveKeys/AnonymousInline',
                'pageType' => 'Inertia.SharedData & { teams: Omit<JsonResourcePaginator<PreserveKeysTeamResource>, \'data\'> & { data: Record<string, PreserveKeysTeamResource> } }',
                'imports' => ['@tolki/types::AnonymousResourceCollection', '@tolki/types::JsonResourcePaginator', 'Workbench\App\Http\Resources\PreserveKeysTeamResource'],
            ],
            'native' => [
                'component' => 'PreserveKeys/AnonymousInline',
                'pageType' => 'Inertia.SharedData & { teams: Omit<JsonResourcePaginator<PreserveKeysTeamResource>, \'data\'> & { data: Record<string, PreserveKeysTeamResource> } }',
                'imports' => ['@tolki/types::JsonResourcePaginator', 'Workbench\App\Http\Resources\PreserveKeysTeamResource'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaUserShapesController@index' => [
            'surveyor' => [
                'component' => 'UserShapes/Index',
                'pageType' => 'Inertia.SharedData & { users: unknown[], posts: unknown[], page: unknown }',
                'imports' => [],
            ],
            'native' => [
                'component' => 'UserShapes/Index',
                'pageType' => 'Inertia.SharedData & { users: User[], posts: Post[], page: number }',
                'imports' => ['Workbench\App\Models\Post', 'Workbench\App\Models\User'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaUserShapesController@show' => [
            'surveyor' => [
                'component' => 'UserShapes/Show',
                'pageType' => 'Inertia.SharedData & { post: string, draft: string | null }',
                'imports' => [],
            ],
            'native' => [
                'component' => 'UserShapes/Show',
                'pageType' => 'Inertia.SharedData & { post: Post, draft: Post | null }',
                'imports' => ['Workbench\App\Models\Post'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaUserShapesController@deferred' => [
            'surveyor' => [
                'component' => 'UserShapes/Deferred',
                'pageType' => 'Inertia.SharedData & { comments: unknown, tally: unknown }',
                'imports' => [],
            ],
            'native' => [
                'component' => 'UserShapes/Deferred',
                'pageType' => 'Inertia.SharedData & { comments?: Comment[], tally?: number }',
                'imports' => ['Workbench\App\Models\Comment'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaUserShapesController@compacted' => [
            'surveyor' => [
                'component' => 'UserShapes/Compacted',
                'pageType' => 'Inertia.SharedData & { 0: string, 1: unknown[] }',
                'imports' => [],
            ],
            'native' => [
                'component' => 'UserShapes/Compacted',
                'pageType' => 'Inertia.SharedData & { post: Post, comments: Comment[] }',
                'imports' => ['Workbench\App\Models\Comment', 'Workbench\App\Models\Post'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaUserShapesController@toggled' => [
            'surveyor' => null,
            'native' => [
                'component' => 'UserShapes/Toggled',
                'pageType' => 'Inertia.SharedData & { post: Post | null, views?: number }',
                'imports' => ['Workbench\App\Models\Post'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaUserShapesController@profile' => [
            'surveyor' => [
                'component' => 'UserShapes/Profile',
                'pageType' => 'Inertia.SharedData & { user: User | null, stats: { views: number, likes: number } }',
                'imports' => ['Workbench\App\Models\User'],
            ],
            'native' => [
                'component' => 'UserShapes/Profile',
                'pageType' => 'Inertia.SharedData & { user: User | null, stats: { views: number; likes: number } }',
                'imports' => ['Workbench\App\Models\User'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaUserShapesController@merged' => [
            'surveyor' => [
                'component' => 'UserShapes/Merged',
                'pageType' => 'Inertia.SharedData & { title: string, extra: true }',
                'imports' => [],
            ],
            'native' => [
                'component' => 'UserShapes/Merged',
                'pageType' => 'Inertia.SharedData & { title: string, extra: boolean }',
                'imports' => [],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaUserShapesController@branched' => [
            'surveyor' => [
                'component' => 'UserShapes/Branched',
                'pageType' => 'Inertia.SharedData & { post: string | null, detail?: string }',
                'imports' => [],
            ],
            'native' => [
                'component' => 'UserShapes/Branched',
                'pageType' => 'Inertia.SharedData & { post: Post | null, detail?: string }',
                'imports' => ['Workbench\App\Models\Post'],
            ],
        ],
        'Workbench\App\Http\Controllers\InertiaUserShapesController@bound' => [
            'surveyor' => [
                'component' => 'UserShapes/Bound',
                'pageType' => 'Inertia.SharedData & { post: Post, stats: { views: number, likes: number } }',
                'imports' => ['Workbench\App\Models\Post'],
            ],
            'native' => [
                'component' => 'UserShapes/Bound',
                'pageType' => 'Inertia.SharedData & { post: Post, stats: { views: number; likes: number } }',
                'imports' => ['Workbench\App\Models\Post'],
            ],
        ],
    ];
}

test('the native page analyzer matches the Surveyor one except on the listed improvements', function () {
    $surveyor = resolve(InertiaPageAnalyzer::class);
    $native = new NativeInertiaPageAnalyzer;

    $differences = [];

    foreach (parityActions() as $uses) {
        $before = parityShape($surveyor->analyze(['uses' => $uses]));
        $after = parityShape($native->analyze(['uses' => $uses]));

        if ($before !== $after) {
            $differences[$uses] = ['surveyor' => $before, 'native' => $after];
        }
    }

    expect($differences)->toEqual(parityExpectedDifferences());
});
