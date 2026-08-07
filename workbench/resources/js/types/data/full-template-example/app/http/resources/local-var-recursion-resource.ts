/**
 * Regression fixture (Task 12 review, Minor b): mutual (`$a = $b; $b = $a;`) and
 * self (`$c = $c;`) referential local variable bindings must terminate instead of
 * hanging the generator, degrading to unknown rather than infinitely recursing. A
 * regression here manifests as a CI hang, not a test failure, so this is committed
 * rather than left as a throwaway verification.
 *
 * @see Workbench\App\Http\Resources\LocalVarRecursionResource
 */
export interface LocalVarRecursionResource
{
    mutual: unknown;
    self: unknown;
}
