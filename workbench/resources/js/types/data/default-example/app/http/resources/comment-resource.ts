import type { RoleType, StatusType } from '../../enums';
import type { Post, Profile } from '../../models';
import type { PostResource, UserResource } from '.';

/** @see Workbench\App\Http\Resources\CommentResource */
export interface CommentResource
{
    id: number;
    content: string;
    is_flagged: boolean;
    flagged_at?: string | null;
    metadata: Record<string, unknown>;
    author?: UserResource;
    author_new?: UserResource;
    author_direct: UserResource;
    post?: PostResource;
    post_new?: PostResource;
    post_direct: PostResource;
    post_limited: Pick<Post, 'id' | 'title'>;
    post_extended: Pick<Post, 'id' | 'title' | 'content' | 'user_id' | 'status' | 'published_at' | 'metadata' | 'rating' | 'category' | 'options' | 'deleted_at' | 'category_id' | 'visibility' | 'priority' | 'word_count' | 'reading_time_minutes' | 'featured_image_url' | 'is_pinned'> | null;
    post_excerpt_only: { id: number; excerpt: string | null };
    post_title?: string;
    post_content?: string | null;
    post_title_display?: string | null;
    post_author?: string | null;
    post_resource_title?: string;
    post_resource_content?: string | null;
    post_resource_title_display?: string | null;
    post_resource_author?: string | null;
    user_name?: string;
    user_email?: string;
    user_email_annotated?: string | null;
    unresolvable_status?: unknown;
    resolvable_status?: StatusType;
    user_name_nullable?: string | null;
    user_email_nullable?: string | null;
    user_role: RoleType | null;
    user_profile: Profile | null;
    user_profile_bio: string | null;
    user_profile_avatar_url: string | null;
}
