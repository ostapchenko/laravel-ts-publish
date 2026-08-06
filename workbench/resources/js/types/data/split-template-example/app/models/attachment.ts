import type { Post } from '.';

/** @see Workbench\App\Models\Attachment */
export interface Attachment
{
    id: number;
    attachable_type: string;
    attachable_id: number;
    filename: string;
    size_bytes: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface AttachmentRelations
{
    // Relations
    /** Polymorphic parent (Post and friends) */
    attachable: Post;
    // Counts
    attachable_count: number;
    // Exists
    attachable_exists: boolean;
}

export interface AttachmentAll extends Attachment, AttachmentRelations {}
