import type { TeamMemberResource, UserResource } from '.';

/**
 * Declares no toArray() of its own — pins that the analyzer walks up to the nearest ancestor
 * that does, rather than emitting an empty interface. Carries its own @mixin.
 *
 * @see Workbench\App\Http\Resources\BodylessTeamResource
 */
export interface BodylessTeamResource
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
