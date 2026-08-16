/**
 * Exercises Laravel 13's #[Table], #[Hidden] and #[Appends] class attributes.
 *
 * @see Workbench\App\Models\Laravel13Attributes
 */
export interface Laravel13Attributes
{
    // Columns
    id: number;
    name: string;
    secret_token: string;
    // Mutators
    /** A computed accessor published as an append only because #[Appends] adds it to getAppends(). */
    label: string;
}
