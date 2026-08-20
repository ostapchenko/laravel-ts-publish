<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Exercises all array-category validation rules:
 * array, array:k1,k2, array_keys, between, contains, doesnt_contain, distinct, in_array,
 * in_array_keys, list, max, min, required_array_keys, size.
 *
 * Also demonstrates nested/wildcard dot-notation rules for validating
 * array elements and deeply nested structures, including a
 * required_array_keys key colliding with a real declared child.
 */
class ArrayRulesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // array — field must be a PHP array
            'tags' => ['array', 'min:1', 'max:10'],
            'tags.*' => ['required', 'string', 'max:50'],

            // array with between — array element count within range
            'selected_ids' => ['required', 'array', 'between:1,5'],
            'selected_ids.*' => ['required', 'integer'],

            // contains — array must contain all of the given values
            'roles' => ['required', 'array', Rule::contains(['user'])],
            'roles.*' => ['required', 'string'],

            // doesnt_contain — array must not contain any of the given values
            'allowed_roles' => ['required', 'array', Rule::doesntContain(['superadmin', 'root'])],
            'allowed_roles.*' => ['required', 'string'],

            // distinct — array elements must not have duplicate values
            'sku_codes' => ['required', 'array'],
            'sku_codes.*' => ['required', 'string', 'distinct'],

            // in_array — field's value must exist in another array field's values
            'airports' => ['required', 'array'],
            'airports.*' => ['required', 'string'],
            'primary_airport' => ['required', 'string', 'in_array:airports.*'],

            // in_array_keys — array must have at least one of the given keys
            'config' => ['required', 'array', 'in_array_keys:timezone'],

            // array_keys:k1,k2 — restricts the array's keys to the given set, like array:k1,k2,
            // but as a standalone rule that itself requires at least one listed key
            'attributes_map' => ['required', 'array_keys:color,size'],

            // array_keys with no parameters — Laravel itself rejects this (requireParameterCount),
            // but the analyzer never runs the real validator, so resolveTsType()'s defensive arm
            // must still degrade it to unknown[] instead of falling through to bare unknown
            'malformed_array_keys' => ['required', 'array_keys'],

            // array:k1,k2 — array's keys are restricted to (at most) the given key set
            'preferences' => ['nullable', 'array:theme,locale'],

            // required_array_keys colliding with a real declared child on "method": the
            // declared rule wins the merge, so "method" keeps its own type/optionality
            // instead of the synthesized required-unknown; "address" stays synthetic.
            'shipping' => ['required', 'array', 'required_array_keys:method,address'],
            'shipping.method' => ['nullable', 'in:standard,express'],

            // list — array must be a list (consecutive integer keys starting at 0)
            'ordered_items' => ['required', 'list'],
            'ordered_items.*' => ['required', 'string'],

            // max (array) — array must have at most this many elements
            'limited_choices' => ['nullable', 'array', 'max:3'],
            'limited_choices.*' => ['nullable', 'string'],

            // min (array) — array must have at least this many elements
            'required_answers' => ['required', 'array', 'min:2'],
            'required_answers.*' => ['required', 'string'],

            // size (array) — array must have exactly this many elements
            'coordinates' => ['required', 'array', 'size:2'],
            'coordinates.*' => ['required', 'numeric'],

            // Nested object-like array with typed sub-fields
            'products' => ['required', 'array', 'min:1'],
            'products.*.name' => ['required', 'string', 'max:100'],
            'products.*.price' => ['required', 'decimal:2'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'products.*.categories' => ['required', 'array'],
            'products.*.categories.*' => ['required', 'string'],
            'products.*.is_available' => ['required', 'boolean'],
            'products.*.notes' => ['nullable', 'string'],
            'products.*.contact_email' => ['required', 'email'],

            // Deep nesting example
            'order' => ['required', 'array'],
            'order.id' => ['required', 'uuid'],
            'order.items' => ['required', 'array', 'min:1'],
            'order.items.*.product_id' => ['required', 'integer'],
            'order.items.*.quantity' => ['required', 'integer', 'min:1'],
            'order.secret' => ['prohibited'],
            'order.secret.token' => ['required', 'uuid'],
        ];
    }
}
