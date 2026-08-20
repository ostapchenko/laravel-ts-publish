<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Workbench\App\Http\Resources\PreserveKeysCollection;
use Workbench\App\Http\Resources\PreserveKeysFlatCollection;
use Workbench\App\Http\Resources\PreserveKeysTeamResource;
use Workbench\App\Models\Team;

class InertiaPreserveKeysController
{
    /** Result should be { teams: PreserveKeysCollection } */
    public function named(): Response
    {
        return Inertia::render('PreserveKeys/Named', [
            'teams' => new PreserveKeysCollection(Team::all()),
        ]);
    }

    /** Result should be { teams: PreserveKeysCollection & ResourcePagination } */
    public function namedPaginated(): Response
    {
        $teams = Team::latest()->paginate(10);

        return Inertia::render('PreserveKeys/NamedPaginated', [
            'teams' => new PreserveKeysCollection($teams),
        ]);
    }

    /** Result should be { teams: Omit<JsonResourcePaginator<TeamResource>, 'data'> & { data: Record<string, TeamResource> } } */
    public function flatPaginated(): Response
    {
        $teams = Team::latest()->paginate(10);

        return Inertia::render('PreserveKeys/FlatPaginated', [
            'teams' => new PreserveKeysFlatCollection($teams),
        ]);
    }

    /** Result should be { teams: Omit<JsonResourcePaginator<PreserveKeysTeamResource>, 'data'> & { data: Record<string, PreserveKeysTeamResource> } } */
    public function anonymousPaginated(): Response
    {
        $teams = Team::latest()->paginate(10);

        return Inertia::render('PreserveKeys/AnonymousPaginated', [
            'teams' => PreserveKeysTeamResource::collection($teams),
        ]);
    }

    /**
     * Paginates inline inside the render array with no intermediate variable — pins that the
     * paginator is still detected, so the prop types as a paginator and not a bare collection.
     */
    public function inlinePaginated(): Response
    {
        return Inertia::render('PreserveKeys/Inline', [
            'teams' => new PreserveKeysCollection(Team::query()->paginate(10)),
        ]);
    }

    /**
     * Calls Resource::collection() on a paginator invoked inline, with no intermediate variable —
     * pins that resolveStaticCollectionProps() also resolves the inline form, not just the
     * resource-constructor form inlinePaginated() above exercises.
     */
    public function anonymousInlinePaginated(): Response
    {
        return Inertia::render('PreserveKeys/AnonymousInline', [
            'teams' => PreserveKeysTeamResource::collection(Team::query()->paginate(10)),
        ]);
    }
}
