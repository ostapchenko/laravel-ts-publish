import type { User as CrmUser } from '../../../crm/models';
import type { Post, Product, User as WorkbenchUser } from '../../models';

/**
 * Exercises a morphTo relation exposed through a resource: the emitted union needs every parent imported.
 *
 * @see Workbench\App\Http\Resources\ImageMorphResource
 */
export interface ImageMorphResource
{
    id: number;
    imageable: Post | Product | WorkbenchUser | CrmUser;
    uploaders_from_docblock: WorkbenchUser[] | Record<string, WorkbenchUser>;
    imageable_when_loaded?: Post | Product | WorkbenchUser | CrmUser;
}
