import type { RoleType } from '../../enums';
import type { EventLogResource, RegistrarResource, SupplierSummaryResource, UserResource } from '.';

/**
 * Exercises Model::toResource() / Collection::toResourceCollection() resolution: naming
 * convention, #[UseResource], explicit arguments, the unresolvable negative cases, and
 * (registrar/registrars/suppliers) the three resolution orderings against a losing
 * candidate that also exists, so an inverted order would visibly fail.
 *
 * Also reuses the staff/registrars/historyEvent relations for the ->map->only()/->except()
 * HigherOrderCollectionProxy: a to-many whenLoaded param is a bound collection and matches,
 * a singular one (historyEvent) is not and must stay unknown.
 *
 * @see Workbench\App\Http\Resources\MerchantResource
 */
export interface MerchantResource
{
    id: number;
    owner_via_closure?: UserResource;
    owner_explicit?: UserResource;
    owner_direct: UserResource;
    staff_via_closure?: UserResource[];
    staff_explicit?: UserResource[];
    history_event?: EventLogResource;
    filing?: unknown;
    alert?: unknown;
    registrar?: RegistrarResource;
    registrars?: unknown;
    suppliers?: SupplierSummaryResource[];
    staff_map_only?: ({ id: number; name: string; role: RoleType | null; last_login_at: string | null })[];
    registrars_map_except?: { name: string }[];
    history_event_map_only?: unknown;
}
