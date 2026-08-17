import type { EventLogResource, RegistrarResource, SupplierSummaryResource, UserResource } from '.';

/**
 * Exercises Model::toResource() / Collection::toResourceCollection() resolution: naming
 * convention, #[UseResource], explicit arguments, the unresolvable negative cases, and
 * (registrar/registrars/suppliers) the three resolution orderings against a losing
 * candidate that also exists, so an inverted order would visibly fail.
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
}
