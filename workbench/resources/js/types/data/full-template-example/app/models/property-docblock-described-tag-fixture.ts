/**
 * Exercises the `$`-less `@property` tag's no-description restriction: `list` must not be bound
 * to a bogus type resolved from the trailing description of a different (`$`-less) tag.
 *
 * @see Workbench\App\Models\PropertyDocblockDescribedTagFixture
 */
export interface PropertyDocblockDescribedTagFixture
{
    // Columns
    id: number;
    tags: string | null;
    related_users: string | null;
    meta_info: string | null;
    owner_snapshot: string | null;
    created_at: string | null;
    updated_at: string | null;
    // Mutators
    /** Old-style accessor named after the trailing word of this trait's own $-less tag's description. */
    list: unknown[];
}
