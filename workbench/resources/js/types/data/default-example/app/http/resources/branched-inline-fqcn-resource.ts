import type { User as CrmUser } from '../../../crm/models';
import type { User as ModelsUser } from '../../models';

/**
 * Regression fixture: mergeReturnBranches() unions inlineModelFqcns per property key across branches.
 * Deduping that union collapses Warehouse::regionalHub()'s real per-occurrence multiplicity, so once
 * the branch types combine into one union string, an occurrence past the deduped queue's end mistypes.
 * Both branches are nullsafe, so the merged union carries one trailing `| null`, not one per arm.
 *
 * @see Workbench\App\Http\Resources\BranchedInlineFqcnResource
 */
export interface BranchedInlineFqcnResource
{
    regional_hub_contacts: { primaryContact: CrmUser | null; manager: ModelsUser | null } | { manager: ModelsUser | null; secondaryContact: CrmUser | null; primaryContact: CrmUser | null } | null;
}
