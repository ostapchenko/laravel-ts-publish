<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use AbeTwoThree\LaravelTsPublish\Attributes\TsExtends;
use Illuminate\Http\Request;
use Workbench\App\Http\Resources\Concerns\ExtendsInterfaces;

/**
 * Resource with no @mixin or TsResource — tests convention-based model guess.
 * Also tests multiple TsExtends in parent class, trait, and locally.
 */
#[TsExtends('BaseResource', import: '@/types/base')]
class WarehouseResource extends RoutableResource
{
    use ExtendsInterfaces;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'review_priority' => $this->review_priority,
            'review_priority_typed' => $this->review_priority_typed,
            'review_priority_typed_short' => $this->review_priority_typed_short,
            'manager' => $this->manager,
            'primary_contact' => $this->primaryContact,
            'secondary_contact' => $this->secondaryContact,
            'last_user_activity_by' => $this->last_user_activity_by,
            'last_user_activity_by_typed' => $this->last_user_activity_by_typed,
            'last_user_activity_by_typed_short' => $this->last_user_activity_by_typed_short,
            'last_user_activity_by_partial' => $this->last_user_activity_by?->only('id', 'name'),
            'last_user_activity_by_mostly' => $this->last_user_activity_by?->except(['id', 'name']),
            'last_checked_by_mostly' => $this->last_checked_by?->except(['created_at', 'updated_at']),
            // Three relations, two distinct User FQCNs, deliberately interleaved: primaryContact and
            // secondaryContact are Crm\Models\User, manager is App\Models\User, so the occurrence order is
            // Crm, App, Crm. The bare name occurs once more than the property has distinct FQCNs — which the
            // old leftmost-occurrence heuristic left bare and unimportable — and the repeat is NOT the last
            // FQCN, so only a per-occurrence FQCN list resolves it. Deduping the list aliases it App.
            'regional_hub_contacts' => $this->regional_hub?->only(['primaryContact', 'manager', 'secondaryContact']),
            // Inline array literal referencing a multi-FQCN accessor (Crm\User|User) plus a single-FQCN
            // relation: proves an inline object member keeps its own per-occurrence FQCNs rather than
            // losing them to the array literal's self-keyed, deduplicated model FQCN map.
            'probe_nested' => ['first' => $this->last_user_activity_by, 'second' => $this->manager],
            // 'images' is a relation, not a published column, so relationFilterModelReference() rejects the
            // reference and this falls back to inline expansion — keeping an inline arm with an aliased
            // enum (status: CrmStatusType) alive now that the multi-model accessor arms above reference
            // their models directly instead of expanding inline.
            'crm_contact_partial' => $this->primaryContact?->only(['status', 'images']),
            // Mixed-arm union: 'phone' is a column on App\Models\User but not on Crm\Models\User, so the
            // CrmUser arm declines the Pick<> reference and falls back to inline expansion while the
            // App\Models\User arm resolves to Pick<>. Pins that a declining arm's own FQCN is never
            // registered against a text occurrence it never produced, which would misalign the very next
            // Pick<> arm's positional FQCN lookup onto the wrong model.
            'probe_mixed' => $this->last_user_activity_by?->only(['id', 'phone']),
        ];
    }
}
