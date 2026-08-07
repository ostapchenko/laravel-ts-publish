<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use AbeTwoThree\LaravelTsPublish\EnumResource;
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

            // pluck() on a NULLABLE column → the union must be parenthesized before the
            // '[]' suffix (RoleType | null)[], not RoleType | null[] (which TypeScript
            // parses as RoleType | (null[]) — genuinely wrong, not merely ugly).
            'member_roles' => $this->members->pluck('role'),

            // map() body is ENTIRELY EnumResource::make(...) — no array literal wrapping —
            // so the result carries a singular 'enumFqcn' (not 'directEnumFqcn'). Must still
            // render as an array (RoleType[]) and must not let ResourceTransformer's
            // tolki AsEnum rewrite collapse it back down to a singular AsEnum<typeof Role>.
            'member_role_resources' => $this->members->take(5)->map(fn ($member) => EnumResource::make($member->role))->values(),

            // map() argument is a string callable, not a Closure/ArrowFunction — must not be
            // treated as a closure body (which would otherwise resolve 'strtoupper' itself,
            // a plain string literal, as if it were the map's return value).
            'member_names_upper' => $this->members->take(5)->map('strtoupper'),

            // map() argument is an array callable ([$this, 'method']), not a Closure/
            // ArrowFunction — same guard as above, different unsupported argument shape.
            'member_formatted' => $this->members->take(5)->map([$this, 'formatMember']),

            // Unsupported op in the chain (first() isn't an identity op) → stays unknown,
            // same as current (pre-Task-13) behavior.
            'first_member' => $this->members->take(5)->first(),
        ];
    }

    public function formatMember(mixed $member): string
    {
        return (string) $member->name;
    }
}
