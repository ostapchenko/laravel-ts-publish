/**
 * Regression fixture (Task 12 review, Critical 1): $localVarBindings collected for
 * toArray() must not leak into a DIFFERENT method's analysis when that method is
 * reached via a `...$this->method()` spread. `extra()`'s own `$data` (a string
 * literal) is unrelated to toArray()'s `$data` (the order id) even though they share
 * a name.
 *
 * @see Workbench\App\Http\Resources\LocalVarSpreadResource
 */
export interface LocalVarSpreadResource
{
    y: string;
    x: number;
}
