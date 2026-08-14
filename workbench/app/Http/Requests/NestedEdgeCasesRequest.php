<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Composition edge cases: a wildcard beside named children, an all-prohibited object, a
 * prohibited wildcard element, an escaped literal dot, and explicit numeric indices.
 */
class NestedEdgeCasesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Wildcard constrains every value; a named sibling pins one known key.
            'options' => ['array'],
            'options.*' => ['string'],
            'options.default' => ['string'],

            // Every named child prohibited — the object must be empty.
            'meta' => ['array'],
            'meta.secret' => ['prohibited'],

            // Prohibited wildcard element — the array must be empty.
            'empties' => ['array'],
            'empties.*' => ['prohibited'],

            // Escaped dot — Laravel reads this as one attribute literally named `v1.0`.
            'v1\.0' => ['required', 'string'],

            // Explicit numeric indices — a list, not an object with a "0" key.
            'items' => ['array'],
            'items.0.name' => ['required', 'string'],

            // Two numeric indices with different shapes — the union must be parenthesized.
            'variants' => ['array'],
            'variants.0.name' => ['required', 'string'],
            'variants.1.email' => ['required', 'email'],

            // `in:` values containing bracket characters must not unbalance the union scan.
            'markers' => ['array'],
            'markers.*' => ['in:>a,b'],

            // An apostrophe inside an "in:" value must be escaped, not interpolated raw into the literal.
            'quoted' => ["in:it's,b"],

            // A mixed node as an array element — the intersection must be parenthesized.
            'buckets' => ['array'],
            'buckets.*' => ['array'],
            'buckets.*.*' => ['string'],
            'buckets.*.name' => ['string'],

            // A prohibited wildcard beside a named sibling — the index signature must type as never.
            'settings' => ['array'],
            'settings.*' => ['prohibited'],
            'settings.color' => ['string'],
        ];
    }
}
