/**
 * Pins ModelAttributeResolver::isStrictlyMoreStructured()'s reject direction: `meta_info` casts
 * to AsArrayObject (Record<string, unknown>) — already more structured than a bare untyped
 * array/collection, not "entirely" vague — so the class's own @property tag, which names a
 * vaguer bare array, must never replace it.
 *
 * @see Workbench\App\Models\PropertyDocblockRejectFixture
 */
export interface PropertyDocblockRejectFixture
{
    // Columns
    id: number;
    tags: string | null;
    related_users: string | null;
    meta_info: Record<string, unknown> | null;
    owner_snapshot: string | null;
    created_at: string | null;
    updated_at: string | null;
}
