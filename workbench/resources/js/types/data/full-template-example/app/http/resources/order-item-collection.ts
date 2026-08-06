import type { OrderItemResource } from '.';

/** @see Workbench\App\Http\Resources\OrderItemCollection */
export interface OrderItemCollection
{
    data: OrderItemResource[];
}
