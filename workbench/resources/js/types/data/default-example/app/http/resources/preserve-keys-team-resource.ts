import type { TeamMemberResource, UserResource } from '.';

/**
 * Singular resource whose ::collection() preserves keys via $preserveKeys — exercises the
 * anonymous (static-collection) Inertia path. Delegates to TeamResource's toArray() shape.
 *
 * @see Workbench\App\Http\Resources\PreserveKeysTeamResource
 */
export interface PreserveKeysTeamResource
{
    id: number;
    name: string;
    slug: string;
    description?: string | null;
    is_active: boolean;
    owner?: UserResource;
    members?: TeamMemberResource[];
    members_count?: number;
    settings?: Record<string, unknown> | null;
}
