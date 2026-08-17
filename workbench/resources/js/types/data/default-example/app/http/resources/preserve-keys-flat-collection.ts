import type { TeamResource } from '.';

/**
 * Key-preserving AND flat ($wrap = null): the paginated Inertia prop must emit a keyed
 * record data member, not JsonResourcePaginator's array one. Property form, so the fixture
 * behaves identically on Laravel 12.
 *
 * @see Workbench\App\Http\Resources\PreserveKeysFlatCollection
 */
export type PreserveKeysFlatCollection = Record<string, TeamResource>;
