<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Team;

/**
 * Regression pin: `$this->map->only([...])` must route through the relation-filter guard, not
 * Laravel's `->map->only()` HigherOrderCollectionProxy guard — both structurally match a relation
 * literally named `map`, so only their declaration order in analyzeValueExpression() keeps this
 * correct. A reorder would silently regress this with a fully green suite otherwise.
 *
 * @mixin Team
 */
class MapRelationFilterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'map' => $this->map->only(['id', 'name']),
        ];
    }
}
