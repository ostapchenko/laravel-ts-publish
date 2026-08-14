import type { User } from '../../models';

/**
 * Exercises the conditional family's third default argument. An explicit default means the key can
 * never be missing, so the property is required and its type unions both arms.
 *
 * @see Workbench\App\Http\Resources\ConditionalDefaultsResource
 */
export interface ConditionalDefaultsResource
{
    not_null_no_default?: string;
    not_null_with_default: string | number;
    not_null_same_type_default: number;
    null_with_default: null | string;
    not_null_explicit_null_default: string | null;
    not_null_named_default?: string;
    not_null_spread_default?: string;
    when_no_default?: string;
    when_with_default: string;
    has_with_default: string;
    loaded_with_default: User;
    counted_with_default: number;
}
