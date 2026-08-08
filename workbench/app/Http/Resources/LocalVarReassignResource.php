<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;

/**
 * Regression fixture (Task 12 review, Important 3): a variable reassigned through a
 * non-`Assign` form — a `foreach` loop's value variable, a compound assignment
 * operator, increment, or a by-reference alias — must be excluded from
 * $localVarBindings just like a plain nested `Assign` would be. Each property here
 * has exactly one TOP-LEVEL `Assign`, so the naive "only look for a second `Assign`"
 * check would have missed all four; each must degrade to unknown.
 *
 * Deliberately NOT covered here: by-reference function/method arguments (e.g.
 * `preg_match($pattern, $subject, $matches)`), which would require resolving the
 * callee's parameter-by-reference signature — not statically knowable in general.
 * See ResourceAstAnalyzer::collectWrittenVariableNames()'s docblock.
 *
 * @mixin Order
 */
class LocalVarReassignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viaForeach = $this->notes;

        foreach ($this->resource->items as $item) {
            $viaForeach = $item;
        }

        $viaConcat = $this->notes;
        $viaConcat .= 's';

        $viaIncrement = $this->id;
        $viaIncrement++;

        $viaRef = $this->notes;
        $alias = &$viaRef;
        $alias = $this->id;

        return [
            'via_foreach' => $viaForeach,
            'via_concat' => $viaConcat,
            'via_increment' => $viaIncrement,
            'via_ref' => $viaRef,
        ];
    }
}
