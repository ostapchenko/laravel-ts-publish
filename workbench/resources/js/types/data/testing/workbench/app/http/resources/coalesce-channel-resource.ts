import type { OrderStatusType } from '../../enums';
import type { User } from '../../models';

/**
 * Regression fixture: `??` used to keep the type string of whichever operand won while dropping its
 * FQCN, so both properties below emitted a token with no import (TS2304). analyzeCoalesce() now
 * carries the surviving operands' channels through the same merge the ternary union uses.
 *
 * @see Workbench\App\Http\Resources\CoalesceChannelResource
 */
export interface CoalesceChannelResource
{
    buyer: User | null;
    status: OrderStatusType;
}
