import type { DatabaseNotification } from '../../../illuminate/notifications';
import type { Activity, Registrar, Supplier, TrackingEvent, User } from '.';

/**
 * Exercises Model::toResource()/Collection::toResourceCollection(): `owner`/`staff` resolve by
 * convention, `historyEvent` via #[UseResource], `filing`/`alert` have no resolvable resource.
 * `registrar`/`registrars`/`suppliers` pin the three resolution orderings against a losing
 * candidate that also exists, so an inverted order would visibly fail (see
 * ResourceAstAnalyzerTest.php's MerchantResource ordering describe block).
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
    registrar: Registrar | null;
    registrars: Registrar[];
    suppliers: Supplier[];
    // Counts
    owner_count: number;
    staff_count: number;
    history_event_count: number;
    filing_count: number;
    alert_count: number;
    registrar_count: number;
    registrars_count: number;
    suppliers_count: number;
    // Exists
    owner_exists: boolean;
    staff_exists: boolean;
    history_event_exists: boolean;
    filing_exists: boolean;
    alert_exists: boolean;
    registrar_exists: boolean;
    registrars_exists: boolean;
    suppliers_exists: boolean;
}

export interface MerchantAll extends Merchant, MerchantRelations {}
