<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use AbeTwoThree\LaravelTsPublish\EnumResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;

/**
 * Exercises collectDirectReturns elseif, else, and loop branches
 * in the main toArray() body (not inside closures).
 *
 * @mixin Order
 */
class ControlFlowReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->status === 'archived') {
            return [
                'id' => $this->id,
                'archived' => true,
                // The only enum reference in this branch lives inside an inline array literal, so it
                // travels inlineEnumResourceFqcns and nothing else — pinning that mergeReturnBranches()
                // carries that channel across branches instead of silently dropping it.
                'inline_enum_branch' => [
                    'method' => EnumResource::make($this->payment_method),
                ],
            ];
        } elseif ($this->status === 'draft') {
            return [
                'id' => $this->id,
                'draft' => true,
            ];
        } else {
            return [
                'id' => $this->id,
                'total' => $this->total,
                'status' => $this->status,
            ];
        }
    }
}
