/**
 * outer() returns $this->helper()->wrongCall() — a method call chained off a non-$this receiver. The
 * resource also defines its own wrongCall(), whose properties must not leak in through that chain.
 *
 * @see Workbench\App\Http\Resources\NonThisReceiverSpreadResource
 */
export interface NonThisReceiverSpreadResource
{
    id: number;
}
