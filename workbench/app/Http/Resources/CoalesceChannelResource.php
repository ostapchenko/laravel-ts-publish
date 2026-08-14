<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Enums\OrderStatus;
use Workbench\App\Models\Order;

/**
 * Regression fixture: `??` used to keep the type string of whichever operand won while dropping its
 * FQCN, so both properties below emitted a token with no import (TS2304). analyzeCoalesce() now
 * carries the surviving operands' channels through the same merge the ternary union uses.
 *
 * @mixin Order
 */
class CoalesceChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // modelFqcn: the left operand resolves to a model token.
            'buyer' => $this->user ?? null,
            // directEnumFqcn: both operands resolve to the same enum token.
            'status' => $this->status ?? OrderStatus::Pending,
        ];
    }
}
