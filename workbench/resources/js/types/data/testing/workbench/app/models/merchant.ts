import type { DatabaseNotification } from '../../../illuminate/notifications';
import type { Activity, TrackingEvent, User } from '.';

/**
 * Exercises Model::toResource()/Collection::toResourceCollection(): `owner`/`staff` resolve by
 * convention, `historyEvent` via #[UseResource], `filing`/`alert` have no resolvable resource.
 *
 * @see Workbench\App\Models\Merchant
 */
export interface Merchant
{
    id: number;
    name: string;
}

export interface MerchantRelations
{
    // Relations
    owner: User | null;
    staff: User[];
    history_event: TrackingEvent | null;
    filing: Activity | null;
    alert: DatabaseNotification | null;
    // Counts
    owner_count: number;
    staff_count: number;
    history_event_count: number;
    filing_count: number;
    alert_count: number;
    // Exists
    owner_exists: boolean;
    staff_exists: boolean;
    history_event_exists: boolean;
    filing_exists: boolean;
    alert_exists: boolean;
}

export interface MerchantAll extends Merchant, MerchantRelations {}
