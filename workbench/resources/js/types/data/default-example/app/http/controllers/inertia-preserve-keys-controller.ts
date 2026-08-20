import { defineRoute, annotatePageProps } from '@tolki/ts';

import type { AnonymousResourceCollection, JsonResourcePaginator, ResourcePagination } from '@tolki/types';
import type { PreserveKeysCollection, PreserveKeysTeamResource, TeamResource } from '../resources';

export type NamedPageProps = Inertia.SharedData & { teams: PreserveKeysCollection };

/** Result should be { teams: PreserveKeysCollection } */
export const named = annotatePageProps<NamedPageProps>()(defineRoute({
    name: 'preserve-keys.named',
    url: '/preserve-keys/named',
    methods: ['get', 'head'] as const,
    component: 'PreserveKeys/Named',
}));

export type NamedPaginatedPageProps = Inertia.SharedData & { teams: PreserveKeysCollection & ResourcePagination };

/** Result should be { teams: PreserveKeysCollection & ResourcePagination } */
export const namedPaginated = annotatePageProps<NamedPaginatedPageProps>()(defineRoute({
    name: 'preserve-keys.named-paginated',
    url: '/preserve-keys/named-paginated',
    methods: ['get', 'head'] as const,
    component: 'PreserveKeys/NamedPaginated',
}));

export type FlatPaginatedPageProps = Inertia.SharedData & { teams: Omit<JsonResourcePaginator<TeamResource>, 'data'> & { data: Record<string, TeamResource> } };

/** Result should be { teams: Omit<JsonResourcePaginator<TeamResource>, 'data'> & { data: Record<string, TeamResource> } } */
export const flatPaginated = annotatePageProps<FlatPaginatedPageProps>()(defineRoute({
    name: 'preserve-keys.flat-paginated',
    url: '/preserve-keys/flat-paginated',
    methods: ['get', 'head'] as const,
    component: 'PreserveKeys/FlatPaginated',
}));

export type AnonymousPaginatedPageProps = Inertia.SharedData & { teams: Omit<JsonResourcePaginator<PreserveKeysTeamResource>, 'data'> & { data: Record<string, PreserveKeysTeamResource> } };

/** Result should be { teams: Omit<JsonResourcePaginator<PreserveKeysTeamResource>, 'data'> & { data: Record<string, PreserveKeysTeamResource> } } */
export const anonymousPaginated = annotatePageProps<AnonymousPaginatedPageProps>()(defineRoute({
    name: 'preserve-keys.anonymous-paginated',
    url: '/preserve-keys/anonymous-paginated',
    methods: ['get', 'head'] as const,
    component: 'PreserveKeys/AnonymousPaginated',
}));

/** @see Workbench\App\Http\Controllers\InertiaPreserveKeysController */
const InertiaPreserveKeysController = {
    named,
    namedPaginated,
    flatPaginated,
    anonymousPaginated,
};

export default InertiaPreserveKeysController;
