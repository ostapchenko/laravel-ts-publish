<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Regression fixture, two-sided:
 *
 * - regional_hub_contacts overrides the spread-in parent property with a different occurrence order,
 *   exercising the analyzeReturnArray() unset() that must clear a parent's stale inline FQCNs before
 *   the override's own occurrences are pushed — otherwise the parent's queue leaks into the override.
 * - regional_hub_leads is spread straight through from the parent with no override, exercising
 *   syncAnalysisMaps()'s dedupe of the merged queue on its own, independent of the unset.
 */
class ChildInlineFqcnResource extends InheritedInlineFqcnResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'regional_hub_contacts' => $this->regional_hub?->only(['manager', 'secondaryContact', 'primaryContact', 'last_checked_by']),
        ];
    }
}
