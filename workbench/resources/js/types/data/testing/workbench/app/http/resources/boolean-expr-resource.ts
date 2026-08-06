/**
 * Exercises comparison and boolean operator expressions.
 *
 * @see Workbench\App\Http\Resources\BooleanExprResource
 */
export interface BooleanExprResource
{
    is_recent: boolean;
    is_equal: boolean;
    is_large: boolean;
    both: boolean;
    negated: boolean;
    is_order: boolean;
    has_notes: boolean;
    no_notes: boolean;
    compared: number;
    price_float: number;
    user_bio?: string | null;
}
