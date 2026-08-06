/**
 * Child of PropertyDocblockBase that redeclares `@property` for the same
 * `tags` column with a different shape. Proves refineWithPropertyDocblock()
 * walks the reflection chain child-first — this class's own tag must win
 * over the parent's, not merely be found alongside it.
 *
 * @see Workbench\App\Models\PropertyDocblockChild
 */
export interface PropertyDocblockChild
{
    // Columns
    id: number;
    tags: string[] | null;
    related_users: string | null;
    meta_info: string | null;
    owner_snapshot: string | null;
    created_at: string | null;
    updated_at: string | null;
}
