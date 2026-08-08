<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;

/**
 * Regression fixture (Task 12 review, Critical 2): two TOP-LEVEL assignments to the
 * same variable, separated by a guard-clause return, must not resolve either return
 * branch through a single static binding — which assignment was "last" depends on
 * which branch actually ran, and this analyzer does not do flow analysis. Both
 * `early` and `late` must degrade to unknown rather than resolving through
 * whichever assignment happens to be recorded.
 *
 * @mixin Order
 */
class LocalVarGuardClauseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $x = $this->notes;

        if ($request->boolean('early')) {
            return ['early' => $x];
        }

        $x = $this->resource->getKey();

        return ['late' => $x];
    }
}
