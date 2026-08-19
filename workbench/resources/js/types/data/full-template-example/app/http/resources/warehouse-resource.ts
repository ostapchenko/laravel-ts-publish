import type { BaseResource } from '@/types/base';
import type { ResourceRoutes } from '@/types/resources';
import type { Routable } from '@/types/routing';
import type { Timestamps } from '@/types/util';
import type { StatusType as CrmStatusType } from '../../../crm/enums';
import type { User as CrmUser } from '../../../crm/models';
import type { ColorType, MembershipLevelType, PriorityType, RoleType, StatusType as EnumsStatusType } from '../../enums';
import type { User as ModelsUser } from '../../models';

/**
 * Resource with no @mixin or TsResource — tests convention-based model guess.
 * Also tests multiple TsExtends in parent class, trait, and locally.
 *
 * @see Workbench\App\Http\Resources\WarehouseResource
 */
export interface WarehouseResource extends BaseResource, ExtendableInterface, Omit<Timestamps, "created_at" | "updated_at">, ResourceRoutes, Pick<Routable, "store" | "update">
{
    id: number;
    name: string;
    color: ColorType | null;
    review_priority: EnumsStatusType | PriorityType | null;
    review_priority_typed: EnumsStatusType | PriorityType | null;
    review_priority_typed_short: EnumsStatusType | PriorityType | null;
    manager: ModelsUser | null;
    primary_contact: CrmUser | null;
    secondary_contact: CrmUser | null;
    last_user_activity_by: CrmUser | ModelsUser | null;
    last_user_activity_by_typed: CrmUser | ModelsUser | null;
    last_user_activity_by_typed_short: CrmUser | ModelsUser | null;
    last_user_activity_by_partial: { id: number; name: string } | null;
    last_user_activity_by_mostly: { email: string; company: string | null; status: CrmStatusType; created_at: string | null; updated_at: string | null } | { email: string; email_verified_at: string | null; password: string; options: unknown[] | null; remember_token: string | null; created_at: string | null; updated_at: string | null; role: RoleType | null; membership_level: MembershipLevelType | null; phone: string | null; avatar: string | null; bio: string | null; settings: unknown[] | null; last_login_at: string | null; last_login_ip: string | null } | null;
    last_checked_by_mostly: { id: number; imageable_type: string; imageable_id: number; url: string; alt_text: string | null; disk: string; path: string; mime_type: string; size_bytes: number; width: number | null; height: number | null; sort_order: number; metadata: unknown[] | null } | { id: number; name: string; email: string; email_verified_at: string | null; password: string; options: unknown[] | null; remember_token: string | null; role: RoleType | null; membership_level: MembershipLevelType | null; phone: string | null; avatar: string | null; bio: string | null; settings: unknown[] | null; last_login_at: string | null; last_login_ip: string | null } | null;
    regional_hub_contacts: { manager: ModelsUser | null; primaryContact: CrmUser | null; secondaryContact: CrmUser | null } | null;
}
