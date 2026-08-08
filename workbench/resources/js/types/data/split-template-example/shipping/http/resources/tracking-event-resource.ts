import type { ShipmentStatusType } from '../../enums';
import type { Shipment } from '../../models';

/**
 * Exercises: direct enum property access ($this->status),
 * whenLoaded bare on same-module relation (Shipment).
 *
 * @see Workbench\Shipping\Http\Resources\TrackingEventResource
 */
export interface TrackingEventResource
{
    id: number;
    status: ShipmentStatusType;
    location: string | null;
    description: string | null;
    occurred_at: string;
    shipment?: Shipment;
}
