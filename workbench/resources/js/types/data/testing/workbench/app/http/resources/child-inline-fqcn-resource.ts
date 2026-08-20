import type { User as CrmUser } from '../../../crm/models';
import type { User as WorkbenchUser } from '../../models';

/**
 * Regression fixture, two-sided:
 *
 * - regional_hub_contacts overrides the spread-in parent property with a different occurrence order,
 * exercising the analyzeReturnArray() unset() that must clear a parent's stale inline FQCNs before
 * the override's own occurrences are pushed — otherwise the parent's queue leaks into the override.
 * - regional_hub_leads is spread straight through from the parent with no override, exercising
 * syncAnalysisMaps()'s dedupe of the merged queue on its own, independent of the unset.
 *
 * @see Workbench\App\Http\Resources\ChildInlineFqcnResource
 */
export interface ChildInlineFqcnResource
{
    id: number;
    regional_hub_contacts: { manager: WorkbenchUser | null; secondaryContact: CrmUser | null; primaryContact: CrmUser | null } | null;
    regional_hub_leads: { primaryContact: CrmUser | null; manager: WorkbenchUser | null; secondaryContact: CrmUser | null } | null;
}
