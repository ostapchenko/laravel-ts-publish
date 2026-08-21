import type { BaseResource } from '@/types/base';
import type { ResourceRoutes } from '@/types/resources';
import type { Routable } from '@/types/routing';
import type { Timestamps } from '@/types/util';
import type { StatusType as CrmStatusType } from '../../../crm/enums';
import type { User as CrmUser } from '../../../crm/models';
import type { ColorType, PriorityType, StatusType as EnumsStatusType } from '../../enums';
import type { Image, User as ModelsUser } from '../../models';

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
    last_user_activity_by_partial: Pick<CrmUser, 'id' | 'name'> | Pick<ModelsUser, 'id' | 'name'> | null;
    last_user_activity_by_mostly: Pick<CrmUser, 'email' | 'company' | 'status' | 'created_at' | 'updated_at'> | Pick<ModelsUser, 'email' | 'email_verified_at' | 'password' | 'options' | 'remember_token' | 'created_at' | 'updated_at' | 'role' | 'membership_level' | 'phone' | 'avatar' | 'bio' | 'settings' | 'last_login_at' | 'last_login_ip'> | null;
    last_checked_by_mostly: Pick<Image, 'id' | 'imageable_type' | 'imageable_id' | 'url' | 'alt_text' | 'disk' | 'path' | 'mime_type' | 'size_bytes' | 'width' | 'height' | 'sort_order' | 'metadata'> | Pick<ModelsUser, 'id' | 'name' | 'email' | 'email_verified_at' | 'password' | 'options' | 'remember_token' | 'role' | 'membership_level' | 'phone' | 'avatar' | 'bio' | 'settings' | 'last_login_at' | 'last_login_ip'> | null;
    regional_hub_contacts: { primaryContact: CrmUser | null; manager: ModelsUser | null; secondaryContact: CrmUser | null } | null;
    probe_nested: { first: CrmUser | ModelsUser | null; second: ModelsUser | null };
    crm_contact_partial: { status: CrmStatusType; images: Image[] } | null;
}
