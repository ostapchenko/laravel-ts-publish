<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Team;

/**
 * Guards against the regression narrowing collectWrittenVariableNames() could introduce: a closure
 * param must shadow its outer local only inside a scope that actually binds it. `when()`'s condition
 * here isn't a `$this->prop` test, so bindClosureParamsFromCondition() binds nothing, and `shadowed`
 * must stay unknown rather than leaking the outer `$slug` local's type.
 *
 * @mixin Team
 */
class ShadowedClosureParamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $slug = $this->slug;

        return [
            'outer' => $slug,
            'shadowed' => $this->when($request->user() !== null, fn ($slug) => $slug),
        ];
    }
}
