import { type AsEnum } from '@tolki/ts';

import { Status } from '../enums';
import type { StatusType } from '../enums';
import type { User } from '.';

/** @see Workbench\App\Models\Team */
export interface Team
{
    // Columns
    id: number;
    name: string;
    slug: string;
    description: string | null;
    owner_id: number;
    is_active: boolean;
    settings: Record<string, unknown> | null;
    grid_config: { filters?: Record<string, unknown>; sorts?: string[]; columns?: string[] } | null;
    created_at: string | null;
    updated_at: string | null;
    deleted_at: string | null;
    week_days: StatusType[] | null;
    grid_configs: { label: string; config: Record<string, unknown> }[] | null;
    // Mutators
    /** Whether the team has any members */
    has_member: boolean;
    /** Number of members */
    member_count: number;
    // Relations
    /** The user who owns this team */
    owner: User;
    /** Members of the team (pivot includes role and joined_at) */
    members: User[];
    // Counts
    owner_count: number;
    members_count: number;
    // Exists
    owner_exists: boolean;
    members_exists: boolean;
}

export interface TeamResource extends Omit<Team, 'week_days'>
{
    week_days: AsEnum<typeof Status>[] | null;
}
