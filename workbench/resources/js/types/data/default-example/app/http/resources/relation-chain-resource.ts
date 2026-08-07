import type { RoleType } from '../../enums';
import type { User } from '../../models';

/**
 * Exercises collection method chains rooted at a many-relation
 * ($this->members->take(5)->map(...)->values()).
 *
 * @see Workbench\App\Http\Resources\RelationChainResource
 */
export interface RelationChainResource
{
    first_members: User[];
    member_cards: { id: number; name: string }[];
    member_profiles: ({ id: number; role: RoleType | null; owner: User })[];
    member_emails: string[];
    first_member: unknown;
}
