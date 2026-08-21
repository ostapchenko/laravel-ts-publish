import type { Store } from '.';

/**
 * A collection of admin stores.
 *
 * @see Workbench\App\Http\Resources\Admin\StoreCollection
 */
export interface StoreCollection
{
    data: Store[];
}
