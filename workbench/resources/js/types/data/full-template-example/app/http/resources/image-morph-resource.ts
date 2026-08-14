import type { User as CrmUser } from '../../../crm/models';
import type { Post, Product, User as ModelsUser } from '../../models';

/**
 * Exercises a morphTo relation exposed through a resource: the emitted union needs every parent imported.
 *
 * @see Workbench\App\Http\Resources\ImageMorphResource
 */
export interface ImageMorphResource
{
    id: number;
    imageable: Post | Product | ModelsUser | CrmUser;
    uploaders_from_docblock: ModelsUser[];
    imageable_when_loaded?: Post | Product | ModelsUser | CrmUser;
}
