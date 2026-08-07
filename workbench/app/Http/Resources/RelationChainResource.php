<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Team;

/**
 * Exercises collection method chains rooted at a many-relation
 * ($this->members->take(5)->map(...)->values()).
 *
 * @mixin Team
 */
class RelationChainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Identity-only chain: no map(), stays the relation's element type.
            'first_members' => $this->members->take(5)->values(),

            // map() with an inline array body → inline object shape, array-wrapped.
            'member_cards' => $this->members->take(5)->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
            ])->values(),

            // map() body embeds an enum-cast column (Role, via the mapped $member) AND a
            // model-backed relation ($this->owner, resolved against the OUTER resource's
            // model since arrow functions capture $this) — proves embeddedEnumFqcns and
            // embeddedModelFqcns both propagate through the chain analyzer's ...$bodyResult
            // spread, not just embeddedResourceFqcns.
            'member_profiles' => $this->members->take(5)->map(fn ($member) => [
                'id' => $member->id,
                'role' => $member->role,
                'owner' => $this->owner,
            ])->values(),

            // pluck() after the relation root → the plucked column's type, array-wrapped.
            'member_emails' => $this->members->pluck('email'),

            // Unsupported op in the chain (first() isn't an identity op) → stays unknown,
            // same as current (pre-Task-13) behavior.
            'first_member' => $this->members->take(5)->first(),
        ];
    }
}
