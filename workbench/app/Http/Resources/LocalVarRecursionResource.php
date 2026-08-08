<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;

/**
 * Regression fixture (Task 12 review, Minor b): mutual (`$a = $b; $b = $a;`) and
 * self (`$c = $c;`) referential local variable bindings must terminate instead of
 * hanging the generator, degrading to unknown rather than infinitely recursing. A
 * regression here manifests as a CI hang, not a test failure, so this is committed
 * rather than left as a throwaway verification.
 *
 * @mixin Order
 */
class LocalVarRecursionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $a = $b;
        $b = $a;
        $c = $c;

        return [
            'mutual' => $a,
            'self' => $c,
        ];
    }
}
