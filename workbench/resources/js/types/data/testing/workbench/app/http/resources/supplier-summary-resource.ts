/**
 * The resource SupplierCollection actually collects — must win over the bare SupplierResource
 * fallback when toResourceCollection() resolves a Supplier[] collection by naming convention.
 *
 * @see Workbench\App\Http\Resources\SupplierSummaryResource
 */
export interface SupplierSummaryResource
{
    id: number;
}
