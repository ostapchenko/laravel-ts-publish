/**
 * Composition edge cases: a wildcard beside named children, an all-prohibited object, a
 * prohibited wildcard element, an escaped literal dot, and explicit numeric indices.
 *
 * @see Workbench\App\Http\Requests\NestedEdgeCasesRequest
 */
export interface NestedEdgeCasesRequest {
    options?: { default?: string } & Record<string, string>;
    meta?: Record<string, never>;
    empties?: never[];
    "v1.0": string;
    items?: { name: string }[];
    /** @format email variants.1.email */
    variants?: ({ name: string } | { email: string })[];
    markers?: ('>a' | 'b')[];
    buckets?: ({ name?: string } & Record<string, string>)[];
}
