<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Exercises the fluent `Rule::arrayKeys()` object form (Laravel 13.24+), kept separate from
 * ArrayRulesRequest and RuleClassRequest so this version-gated call can't fatal their tests
 * on an older supported Laravel version.
 */
class ArrayKeysObjectFormRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attributes_map' => ['required', Rule::arrayKeys(['color', 'size'])],
        ];
    }
}
