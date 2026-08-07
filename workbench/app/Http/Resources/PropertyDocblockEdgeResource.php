<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\PropertyDocblockEdge;

/**
 * Exercises a class-typed `@property` tag reaching a resource, where the token still needs its import.
 *
 * @mixin PropertyDocblockEdge
 */
class PropertyDocblockEdgeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_snapshot' => $this->owner_snapshot,
            'meta_info' => $this->meta_info,
            'tags' => $this->tags,
        ];
    }
}
