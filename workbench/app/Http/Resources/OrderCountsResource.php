<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;

/**
 * Exercises withCount()/withExists() virtual attributes and camelCase
 * attribute access, both resolved via ModelAttributeResolver's fallbacks
 * rather than a literal attribute match.
 *
 * @mixin Order
 */
class OrderCountsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // withCount('items') virtual attribute — not a real column, resolved
            // via the {relation}_count fallback to 'number'.
            'items_count' => $this->items_count,
            // withExists('items') virtual attribute — resolved via the
            // {relation}_exists fallback to 'boolean'.
            'items_exists' => $this->items_exists,
            // camelCase access to the snake_case 'formatted_total' accessor —
            // resolved via the snake_case fallback to the accessor's own type.
            'formatted_total_camel' => $this->formattedTotal,
        ];
    }
}
