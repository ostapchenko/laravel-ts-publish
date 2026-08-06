import type { Post } from '.';

/** @see Workbench\App\Models\Attachment */
export interface Attachment
{
    // Columns
    id: number;
    attachable_type: string;
    attachable_id: number;
    filename: string;
    size_bytes: number;
    created_at: string | null;
    updated_at: string | null;
    // Relations
    /** Polymorphic parent (Post and friends) */
    attachable: Post;
    // Counts
    attachable_count: number;
    // Exists
    attachable_exists: boolean;
}
