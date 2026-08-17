import type { SupplierSummaryResource } from '.';

/**
 * The guessed {Supplier}Collection class — must be tried before the bare SupplierResource
 * fallback, and collects SupplierSummaryResource rather than SupplierResource so the two
 * possible orderings produce visibly different element types.
 *
 * @see Workbench\App\Http\Resources\SupplierCollection
 */
export interface SupplierCollection
{
    data: SupplierSummaryResource[];
}
