import type { SupplierSummaryResource } from '.';

/**
 * A collection of supplier summaries.
 *
 * @see Workbench\App\Http\Resources\SupplierSummaryCollection
 */
export interface SupplierSummaryCollection
{
    data: SupplierSummaryResource[];
}
