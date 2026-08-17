<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * Exercises spreading a resolved resource inside a NESTED inline array literal — a map()
 * closure's return body — as opposed to the four already-supported top-level toArray() spreads.
 *
 * Mirrors a real-world report: `whenLoaded()` closure -> `$var->map(closure)` -> multi-statement
 * inner closure -> array literal spreading `SomeResource::make($x)->resolve($request)` plus a
 * sibling key. Every layer except the spread-plus-siblings shape already works.
 *
 * @mixin Team
 */
class NestedResourceSpreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // The reported shape verbatim: spread of a resolved resource plus one sibling key,
            // inside a multi-statement inner closure, inside a multi-statement outer closure.
            'members_with_profile' => $this->whenLoaded('members', function ($members) use ($request) {
                $members->loadMissing('profile');

                return $members->map(function (User $member) use ($request) {
                    $member->setRelation('profileCopy', $member->profile);

                    return [
                        ...UserResource::make($member)->resolve($request),
                        'profile' => new ProfileResource($member->profile),
                    ];
                });
            }),

            // Spread alone, no sibling keys — collapses to just the resource type, still
            // array-wrapped by map().
            'members_bare' => $this->whenLoaded('members', fn ($members) => $members->map(
                fn (User $member) => [...UserResource::make($member)->resolve($request)]
            )),

            // Spread of a Model's own toArray() — not a resource — must keep today's behaviour.
            'members_model_spread' => $this->whenLoaded('members', fn ($members) => $members->map(
                fn (User $member) => [...$member->toArray(), 'flag' => true]
            )),

            // Two resource spreads plus a sibling key — intersect each in order.
            'members_double_spread' => $this->whenLoaded('members', fn ($members) => $members->map(
                fn (User $member) => [
                    ...UserResource::make($member)->resolve($request),
                    ...ProfileResource::make($member->profile)->resolve($request),
                    'note' => 'x',
                ]
            )),
        ];
    }
}
