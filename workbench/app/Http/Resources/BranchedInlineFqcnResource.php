<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Warehouse;

/**
 * Regression fixture: mergeReturnBranches() unions inlineModelFqcns per property key across branches.
 * Deduping that union collapses Warehouse::regionalHub()'s real per-occurrence multiplicity, so once
 * the branch types combine into one union string, an occurrence past the deduped queue's end mistypes.
 * Both branches are nullsafe, so the merged union carries one trailing `| null`, not one per arm.
 *
 * @mixin Warehouse
 */
class BranchedInlineFqcnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->id) {
            return [
                'regional_hub_contacts' => $this->regional_hub?->only(['primaryContact', 'manager']),
            ];
        }

        return [
            'regional_hub_contacts' => $this->regional_hub?->only(['manager', 'secondaryContact', 'primaryContact']),
        ];
    }
}
