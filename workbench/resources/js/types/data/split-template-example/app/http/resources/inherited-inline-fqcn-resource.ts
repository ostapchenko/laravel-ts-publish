import type { User as CrmUser } from '../../../crm/models';
import type { User as ModelsUser } from '../../models';

/**
 * Base class for ChildInlineFqcnResource. Both regional_hub_* properties carry Warehouse::regionalHub()'s
 * per-occurrence FQCN multiplicity (Crm, App, Crm) so a child spreading this analysis in through
 * syncAnalysisMaps() can lose it if that merge dedupes.
 *
 * @see Workbench\App\Http\Resources\InheritedInlineFqcnResource
 */
export interface InheritedInlineFqcnResource
{
    id: number;
    regional_hub_contacts: { primaryContact: CrmUser | null; manager: ModelsUser | null; secondaryContact: CrmUser | null } | null;
    regional_hub_leads: { primaryContact: CrmUser | null; manager: ModelsUser | null; secondaryContact: CrmUser | null } | null;
}
