<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;

/**
 * Exercises userland global-helper reflection (route()), Carbon
 * receiver-method inference on a datetime-cast attribute, and the
 * can()/count() known-method rules (Task 11).
 *
 * @mixin Order
 */
class HelperCallResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'route_url' => route('orders.show', $this->resource),
            'ship_date' => $this->created_at->toDateString(),
            'can_edit' => $request->user()->can('update', $this->resource),
            'item_total' => $this->items->count(),
        ];
    }
}
