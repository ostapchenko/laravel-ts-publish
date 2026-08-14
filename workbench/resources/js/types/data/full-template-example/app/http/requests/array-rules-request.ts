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
    config: { timezone?: unknown };
    preferences?: { theme?: unknown; locale?: unknown } | null;
    shipping: { method?: 'standard' | 'express' | null; address: unknown };
    ordered_items: string[];
    limited_choices?: (string | null)[] | null;
    required_answers: string[];
    coordinates: number[];
    /** @format email products.*.contact_email */
    products: { name: string; price: number; quantity: number; categories: string[]; is_available: boolean; notes?: string | null; contact_email: string }[];
    /** @format uuid order.id */
    order: { id: string; items: { product_id: number; quantity: number }[] };
}
