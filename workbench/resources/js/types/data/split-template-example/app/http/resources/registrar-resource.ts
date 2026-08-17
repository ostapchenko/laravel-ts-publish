/**
 * The Resource-suffixed naming candidate for Registrar — must win over the bare Registrar
 * resource below, since Model::guessResourceName() tries the suffixed name first.
 *
 * @see Workbench\App\Http\Resources\RegistrarResource
 */
export interface RegistrarResource
{
    id: number;
}
