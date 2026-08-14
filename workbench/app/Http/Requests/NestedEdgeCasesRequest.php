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
        ];
    }
}
