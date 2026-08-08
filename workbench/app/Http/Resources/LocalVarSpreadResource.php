<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;

/**
 * Regression fixture (Task 12 review, Critical 1): $localVarBindings collected for
 * toArray() must not leak into a DIFFERENT method's analysis when that method is
 * reached via a `...$this->method()` spread. `extra()`'s own `$data` (a string
 * literal) is unrelated to toArray()'s `$data` (the order id) even though they share
 * a name.
 *
 * @mixin Order
 */
class LocalVarSpreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->id;

        return [
            ...$this->extra(),
            'x' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extra(): array
    {
        $data = 'a literal string';

        return [
            'y' => $data,
        ];
    }
}
