/**
 * Exercises toResourceCollection()'s naming-convention order: the guessed SupplierCollection
 * class must win over the bare SupplierResource fallback, and since it collects a different
 * resource (SupplierSummaryResource), the two orderings are visibly distinguishable.
 *
 * @see Workbench\App\Models\Supplier
 */
export interface Supplier
{
    id: number;
    name: string;
}
