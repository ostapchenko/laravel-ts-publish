import { type AsEnum } from '@tolki/ts';

import { ShipmentStatus } from '../enums';
import type { ShipmentStatusType } from '../enums';
import type { Shipment } from '.';

/** @see Workbench\Shipping\Models\TrackingEvent */
export interface TrackingEvent
{
    id: number;
    shipment_id: number;
    status: ShipmentStatusType;
    location: string | null;
    description: string | null;
    occurred_at: string;
    created_at: string | null;
    updated_at: string | null;
}

export interface TrackingEventResource extends Omit<TrackingEvent, 'status'>
{
    status: AsEnum<typeof ShipmentStatus>;
}

export interface TrackingEventRelations
{
    // Relations
    shipment: Shipment;
    // Counts
    shipment_count: number;
    // Exists
    shipment_exists: boolean;
}

export interface TrackingEventAll extends TrackingEvent, TrackingEventRelations {}

export interface TrackingEventAllResource extends TrackingEventResource, TrackingEventRelations {}
