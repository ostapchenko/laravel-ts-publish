<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use AbeTwoThree\LaravelTsPublish\EnumResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * Exercises EnumResource::collection() across its backing shapes: an accessor returning
 * list<Enum>, an AsEnumCollection cast, a first-class callable value, and a local
 * variable ->map() (not $this->relation->map()). All should emit an array-wrapped
 * AsEnum utility type, not the unresolved EnumResource itself.
 *
 * @mixin Team
 */
class EnumCollectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Accessor-backed: Attribute<list<Status>, never>
            'status_history' => EnumResource::collection($this->status_history),

            // AsEnumCollection cast, nullable column
            'week_days' => EnumResource::collection($this->week_days),

            // Inline array: the [] suffix must survive analyzeInlineArray()'s AsEnum rewrite too.
            'wrapped_week_days' => [
                'week_days' => EnumResource::collection($this->week_days),
            ],

            // whenHas() never resolves its value argument — it re-derives the type from the
            // named attribute directly, so this pins the existing (correct, conservative)
            // attribute-derived fallback rather than an AsEnum rewrite. See report.
            'week_days_when_has' => $this->whenHas('week_days', EnumResource::collection(...)),

            // First-class callable inside whenLoaded(): the callable carries no argument at the
            // call site, so the enum can't be resolved statically — must degrade to unknown,
            // not guess. Contrast with PostResource::collection(...) at TagResource.php:30,
            // which resolves fine because its element type comes from the class name alone.
            'members_when_loaded_fcc' => $this->whenLoaded('members', EnumResource::collection(...)),

            // Local variable ->map(), distinct from $this->relation->map(): the outer whenLoaded
            // closure captures $members, which then calls ->map() on a bare variable.
            'members_via_var' => $this->whenLoaded(
                'members',
                fn ($members) => $members->map(fn (User $member) => EnumResource::make($member->role))
            ),
        ];
    }
}
