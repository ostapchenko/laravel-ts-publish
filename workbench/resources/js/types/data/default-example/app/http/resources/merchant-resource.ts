import type { EventLogResource, UserResource } from '.';

/**
 * Exercises Model::toResource() / Collection::toResourceCollection() resolution: naming
 * convention, #[UseResource], explicit arguments, and the unresolvable negative cases.
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
}
