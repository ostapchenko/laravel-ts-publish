/**
 * The bare naming candidate for the Registrar model — deliberately present so the
 * Resource-suffixed-first ordering test is non-vacuous: this class must lose to
 * RegistrarResource.
 *
 * @see Workbench\App\Http\Resources\Registrar
 */
export interface Registrar
{
    id: number;
}
