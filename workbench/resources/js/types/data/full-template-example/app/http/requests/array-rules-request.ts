/**
 * Get the validation rules that apply to the request.
 *
 * @see Workbench\App\Http\Requests\ArrayRulesRequest
 */
export interface ArrayRulesRequest {
    tags?: string[];
    selected_ids: number[];
    roles: string[];
    allowed_roles: string[];
    sku_codes: string[];
    airports: string[];
    primary_airport: string;
    config: unknown[];
    ordered_items: string[];
    limited_choices?: string[] | null;
    required_answers: string[];
    coordinates: number[];
    products: { name: string; price: number; quantity: number; categories: string[]; is_available: boolean; notes?: string | null }[];
    order: { id: string; items: { product_id: number; quantity: number }[] };
}
