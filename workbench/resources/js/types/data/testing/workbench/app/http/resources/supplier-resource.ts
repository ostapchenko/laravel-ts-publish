/**
 * The bare guessed-resource fallback for Supplier — deliberately present so the
 * {Guessed}Collection-first ordering test is non-vacuous: this class must lose to
 * SupplierCollection (which collects SupplierSummaryResource, not this one).
 *
 * @see Workbench\App\Http\Resources\SupplierResource
 */
export interface SupplierResource
{
    id: number;
}
