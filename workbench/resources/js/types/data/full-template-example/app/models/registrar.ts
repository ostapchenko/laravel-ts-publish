/**
 * Exercises two orderings: singular toResource() must prefer the Resource-suffixed naming
 * candidate (RegistrarResource) over the bare one (Registrar), which also exists; and
 * #[UseResourceCollection] must stop hard even when its target's element is undeterminable,
 * never falling through to the RegistrarResource naming-convention guess.
 *
 * @see Workbench\App\Models\Registrar
 */
export interface Registrar
{
    // Columns
    id: number;
    name: string;
}
