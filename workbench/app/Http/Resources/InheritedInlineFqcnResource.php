<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Warehouse;

/**
 * Base class for ChildInlineFqcnResource. Both regional_hub_* properties carry Warehouse::regionalHub()'s
 * per-occurrence FQCN multiplicity (Crm, App, Crm) so a child spreading this analysis in through
 * syncAnalysisMaps() can lose it if that merge dedupes.
 *
 * @mixin Warehouse
 */
class InheritedInlineFqcnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'regional_hub_contacts' => $this->regional_hub?->only(['primaryContact', 'manager', 'secondaryContact']),
            'regional_hub_leads' => $this->regional_hub?->only(['primaryContact', 'manager', 'secondaryContact']),
        ];
    }
}
