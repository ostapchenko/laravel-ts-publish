import type { StatusType } from '../../enums';
import type { User } from '../../models';

/**
 * Regression fixture: analyzeThisMethodCall() spread a reflected TypeScriptTypeInfo straight into its
 * result, whose enumFqcns/classFqcns keys no dispatcher reads — so both properties emitted a token
 * with no import (TS2304). The reflection now goes through acceptReflectedTypeInfo() like every other
 * reflected path. Both methods are `: mixed` so the @return docblock is what resolves them.
 *
 * @see Workbench\App\Http\Resources\ReflectedMethodChannelResource
 */
export interface ReflectedMethodChannelResource
{
    fallback_status: StatusType;
    fallback_owner: User;
}
