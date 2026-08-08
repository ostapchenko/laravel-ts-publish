/**
 * A closure parameter that shadows a top-level local must not resolve through the outer binding.
 *
 * @see Workbench\App\Http\Resources\ClosureParamShadowResource
 */
export interface ClosureParamShadowResource
{
    outer_member: unknown;
    mapped_members: unknown;
    loaded_owner?: unknown;
}
