<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;

/**
 * Exercises local variable type tracking inside toArray().
 *
 * @mixin Order
 */
class LocalVarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $label = $this->notes ?? 'None';
        $key = $this->resource->getKey();

        return [
            'label' => $label,
            'key' => $key,
        ];
    }
}
