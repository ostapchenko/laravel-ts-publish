import type { ProfileResource, UserResource } from '.';

/**
 * Exercises spreading a resolved resource inside a NESTED inline array literal — a map()
 * closure's return body — as opposed to the four already-supported top-level toArray() spreads.
 *
 * Mirrors a real-world report: `whenLoaded()` closure -> `$var->map(closure)` -> multi-statement
 * inner closure -> array literal spreading `SomeResource::make($x)->resolve($request)` plus a
 * sibling key. Every layer except the spread-plus-siblings shape already works.
 *
 * @see Workbench\App\Http\Resources\NestedResourceSpreadResource
 */
export interface NestedResourceSpreadResource
{
    id: number;
    members_with_profile?: (UserResource & { profile: ProfileResource })[];
    members_bare?: UserResource[];
    members_model_spread?: { flag: boolean }[];
    members_double_spread?: (UserResource & ProfileResource & { note: string })[];
}
