import type { Attachment, Post } from '../../models';

/**
 * Exercises a morphTo reached through a relation filter, where the union lands inside an inline shape.
 *
 * `attachment_hidden` is a regression: Attachment::$hidden keeps `internal_notes` out of the emitted
 * model interface, so `Pick<Attachment, 'internal_notes'>` violates `K extends keyof T` (TS2344) —
 * the reference must degrade to inline expansion, which also matches Model::only()'s runtime result.
 *
 * @see Workbench\App\Http\Resources\PostAttachmentFilterResource
 */
export interface PostAttachmentFilterResource
{
    id: number;
    attachment: { id: number; filename: string; attachable: Post };
    attachment_public: Pick<Attachment, 'id' | 'filename'>;
    attachment_hidden: { id: number; internal_notes: string | null };
}
