/**
 * A spread whose call-site casing differs from the declared method. PHP method calls are
 * case-insensitive, so this is valid, runnable code the analyzer must still resolve.
 *
 * @see Workbench\App\Http\Resources\CaseSpreadResource
 */
export interface CaseSpreadResource
{
    id: number;
    case_title: string;
}
