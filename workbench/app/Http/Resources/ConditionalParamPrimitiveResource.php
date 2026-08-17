<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;

/**
 * Exercises issue #38: closure parameter passed by the conditional method.
 * Each field uses a single-param arrow function that returns a scalar primitive.
 *
 * The bug: the analyzer resolves the return type of these closures as `unknown`
 * instead of inferring the scalar type from the return expression.
 *
 * @mixin Order
 */
class ConditionalParamPrimitiveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Arrow fn param → string property
            'user_name' => $this->whenLoaded('user', fn ($user) => $user->name),

            // Arrow fn param → int property
            'user_id' => $this->whenLoaded('user', fn ($user) => $user->id),

            // Arrow fn param → bool cast expression
            'user_verified' => $this->whenLoaded('user', fn ($user) => (bool) $user->email_verified_at),

            // when() with arrow fn receiving the truthy value as param
            'notes_upper' => $this->when($this->notes, fn ($notes) => strtoupper($notes)),

            // whenNotNull($value, $default) invokes the default via value($default) with zero arguments.
            // This closure requires $notes, so the call would throw ArgumentCountError — the analyzer
            // treats the default arm as unreachable and excludes it (notes_length: string).
            'notes_length' => $this->whenNotNull($this->notes, fn ($notes) => strlen($notes)),

            // A closure default whose parameter has its own default invokes cleanly with zero args,
            // so its arm must still union in.
            'notes_length_or_default' => $this->whenNotNull($this->notes, fn ($notes = '') => strlen($notes)),

            // A variadic-only closure default also accepts zero args, so it must still union in too.
            'notes_length_variadic_default' => $this->whenNotNull($this->notes, fn (...$args) => 1),
        ];
    }
}
