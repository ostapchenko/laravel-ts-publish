import type { Attachment, Post } from '../../models';

/**
 * Exercises a morphTo reached through a relation filter, where the union lands inside an inline shape.
 *
 * `attachment_hidden` exercises the ts-publish.models.exclude_hidden coupling: Attachment::$hidden
 * keeps `internal_notes` out of the emitted model interface only when exclude_hidden is enabled, and
 * `Pick<Attachment, 'internal_notes'>` would then violate `K extends keyof T` (TS2344) — the reference
 * must degrade to inline expansion in that case. With the default (exclude_hidden disabled), the
 * column is published and the Pick<> reference is preferred, matching Model::only()'s runtime result
 * either way.
 *
 * @see Workbench\App\Http\Resources\PostAttachmentFilterResource
 */
export interface PostAttachmentFilterResource
{
    id: number;
    attachment: { id: number; filename: string; attachable: Post };
    attachment_public: Pick<Attachment, 'id' | 'filename'>;
    attachment_hidden: Pick<Attachment, 'id' | 'internal_notes'>;
}
