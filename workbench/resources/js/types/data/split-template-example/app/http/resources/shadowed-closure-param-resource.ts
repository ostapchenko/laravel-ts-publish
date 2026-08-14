/**
 * Guards against the regression narrowing collectWrittenVariableNames() could introduce: a closure
 * param must shadow its outer local only inside a scope that actually binds it. `when()`'s condition
 * here isn't a `$this->prop` test, so bindClosureParamsFromCondition() binds nothing, and `shadowed`
 * must stay unknown rather than leaking the outer `$slug` local's type.
 *
 * @see Workbench\App\Http\Resources\ShadowedClosureParamResource
 */
export interface ShadowedClosureParamResource
{
    outer: string;
    shadowed?: unknown;
}
