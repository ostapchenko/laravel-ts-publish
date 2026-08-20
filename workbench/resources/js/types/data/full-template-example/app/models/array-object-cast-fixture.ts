/**
 * Pins the As*ArrayObject cast family's `unknown[] | Record<string, unknown>` map entry in
 * generated output. Reuses the property_docblock_fixtures table's unused owner_snapshot column.
 *
 * @see Workbench\App\Models\ArrayObjectCastFixture
 */
export interface ArrayObjectCastFixture
{
    // Columns
    id: number;
    tags: string | null;
    related_users: string | null;
    meta_info: string | null;
    owner_snapshot: unknown[] | Record<string, unknown> | null;
    created_at: string | null;
    updated_at: string | null;
}
