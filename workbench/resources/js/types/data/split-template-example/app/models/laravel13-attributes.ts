/**
 * Exercises Laravel 13's #[Table], #[Hidden] and #[Appends] class attributes.
 *
 * @see Workbench\App\Models\Laravel13Attributes
 */
export interface Laravel13Attributes
{
    id: number;
    name: string;
    secret_token: string;
    /** A computed accessor published as an append only because #[Appends] adds it to getAppends(). */
    label: string;
}

export interface Laravel13AttributesAll extends Laravel13Attributes {}
