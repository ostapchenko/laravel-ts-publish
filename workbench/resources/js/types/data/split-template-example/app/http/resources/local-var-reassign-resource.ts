/**
 * Regression fixture (Task 12 review, Important 3): a variable reassigned through a
 * non-`Assign` form — a `foreach` loop's value variable, a compound assignment
 * operator, increment, or a by-reference alias — must be excluded from
 * $localVarBindings just like a plain nested `Assign` would be. Each property here
 * has exactly one TOP-LEVEL `Assign`, so the naive "only look for a second `Assign`"
 * check would have missed all four; each must degrade to unknown.
 *
 * Deliberately NOT covered here: by-reference function/method arguments (e.g.
 * `preg_match($pattern, $subject, $matches)`), which would require resolving the
 * callee's parameter-by-reference signature — not statically knowable in general.
 * See ResourceAstAnalyzer::collectWrittenVariableNames()'s docblock.
 *
 * @see Workbench\App\Http\Resources\LocalVarReassignResource
 */
export interface LocalVarReassignResource
{
    via_foreach: unknown;
    via_concat: unknown;
    via_increment: unknown;
    via_ref: unknown;
}
