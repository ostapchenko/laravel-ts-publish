<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;

/**
 * Exercises collectDirectReturns loop branch in toArray(). `$item` is bound to the `items`
 * relation's element model (OrderItem), so `$item->name` resolves instead of degrading to unknown.
 *
 * @mixin Order
 */
class LoopReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        foreach ($this->items as $item) {
            return [
                'id' => $this->id,
                'first_item_name' => $item->name,
            ];
        }

        return [
            'id' => $this->id,
            'total' => $this->total,
        ];
    }
}
