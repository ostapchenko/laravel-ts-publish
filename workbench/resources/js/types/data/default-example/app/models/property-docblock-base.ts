/**
 * Base fixture for ModelAttributeResolver::refineWithPropertyDocblock()'s
 * parent-class walk. `tags` casts to plain `array` (vague on its own); this
 * class's own `@property` tag refines it to a typed Record.
 *
 * @see Workbench\App\Models\PropertyDocblockBase
 */
export interface PropertyDocblockBase
{
    id: number;
    tags: Record<string, string> | null;
    related_users: string | null;
    meta_info: string | null;
    owner_snapshot: string | null;
    created_at: string | null;
    updated_at: string | null;
}
