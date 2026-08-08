import type { Post } from '../../models';

/**
 * Exercises a morphTo reached through a relation filter, where the union lands inside an inline shape.
 *
 * @see Workbench\App\Http\Resources\PostAttachmentFilterResource
 */
export interface PostAttachmentFilterResource
{
    id: number;
    attachment: { id: number; filename: string; attachable: Post };
}
