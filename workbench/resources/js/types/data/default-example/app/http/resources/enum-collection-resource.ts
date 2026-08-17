import { type AsEnum } from '@tolki/ts';

import { Role, Status } from '../../enums';
import type { StatusType } from '../../enums';

/**
 * Exercises EnumResource::collection() across its backing shapes: an accessor returning
 * list<Enum>, an AsEnumCollection cast, a first-class callable value, and a local
 * variable ->map() (not $this->relation->map()). All should emit an array-wrapped
 * AsEnum utility type, not the unresolved EnumResource itself.
 *
 * @see Workbench\App\Http\Resources\EnumCollectionResource
 */
export interface EnumCollectionResource
{
    id: number;
    status_history: AsEnum<typeof Status>[];
    week_days: AsEnum<typeof Status>[] | null;
    wrapped_week_days: { week_days: AsEnum<typeof Status>[] | null };
    week_days_when_has?: AsEnum<typeof Status>[] | null;
    week_days_when_has_default: StatusType[] | null | string;
    status_history_when_appended?: AsEnum<typeof Status>[];
    members_via_var?: AsEnum<typeof Role>[];
}
