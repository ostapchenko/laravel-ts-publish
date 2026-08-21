import { type AsEnum } from '@tolki/ts';

import { Status as EnumsStatus } from '../../../app/enums';
import { Status as CrmStatus } from '../../enums';

/**
 * Exercises two same-named enum consts reachable only through an inline EnumResource
 * wrap, with no top-level reader of either enum anywhere else in the file.
 *
 * @see Workbench\Crm\Http\Resources\DealEnumInlineResource
 */
export interface DealEnumInlineResource
{
    id: number;
    summary: { app_status: AsEnum<typeof EnumsStatus>; crm_status: AsEnum<typeof CrmStatus> };
}
