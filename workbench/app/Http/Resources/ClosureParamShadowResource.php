<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Team;

/**
 * A closure parameter that shadows a top-level local resolves through its own scoped binding
 * (whenLoaded relation / relation-chain element model), and must not leak the outer local's value.
 *
 * `outer_member` is a known over-degradation: the write-count shadow guard in
 * collectWrittenVariableNames() still counts the closure param as a write to `$member`, so the
 * top-level `$member` local is never bound. Narrowing that guard is deferred (see task-11-brief.md).
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
            // 'members' is a to-many relation: the closure param holds the whole collection, not
            // one element, so it must NOT bind to the element model — must stay unknown, not `User`.
            'loaded_members_bare' => $this->whenLoaded('members', fn ($members) => $members),
        ];
    }
}
