import { type AsEnum } from '@tolki/ts';

import { Status } from '../../enums';
import type { StatusType } from '../../enums';
import type { OrderItemResource } from '.';

/**
 * Exercises static-call return type reflection and enum static args (Task 10).
 *
 * @see Workbench\App\Http\Resources\StaticCallResource
 */
export interface StaticCallResource
{
    url: string;
    status_badge: AsEnum<typeof Status>;
    status_const: AsEnum<typeof Status>;
    items: OrderItemResource[];
    default_status: StatusType;
    located_order: unknown;
    new_items: OrderItemResource[];
    menu_settings: unknown;
    status_or_priority: unknown;
    void_return: unknown;
    never_return: unknown;
    mixed_return: unknown;
    order_or_status: unknown;
}
