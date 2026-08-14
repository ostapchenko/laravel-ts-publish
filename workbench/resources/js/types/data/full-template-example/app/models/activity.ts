import type { User } from '.';

/**
 * Fixture: two morphTos on one model. `causer` (trait-provided, see HasRelatableLinkedRecord) is
 * narrowed by its docblock generic; `subject` is left bare and resolves via the reverse map —
 * no model declares a reverse relation for either name, so `subject` stays unknown.
 *
 * @see Workbench\App\Models\Activity
 */
export interface Activity
{
    // Columns
    id: number;
    causer_type: string | null;
    causer_id: number | null;
    subject_type: string | null;
    subject_id: number | null;
    created_at: string | null;
    updated_at: string | null;
    // Relations
    subject: unknown;
    causer: User | null;
    // Counts
    subject_count: number;
    causer_count: number;
    // Exists
    subject_exists: boolean;
    causer_exists: boolean;
}
