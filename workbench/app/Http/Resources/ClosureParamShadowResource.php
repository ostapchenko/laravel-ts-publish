<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Team;

/**
 * A closure parameter that shadows a top-level local must not resolve through the outer binding.
 *
 * @mixin Team
 */
class ClosureParamShadowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $member = $this->slug;
        $owner = $this->description;

        return [
            'outer_member' => $member,
            'mapped_members' => $this->members->take(5)->map(fn ($member) => $member)->values(),
            'loaded_owner' => $this->whenLoaded('owner', function ($owner) {
                return $owner;
            }),
        ];
    }
}
