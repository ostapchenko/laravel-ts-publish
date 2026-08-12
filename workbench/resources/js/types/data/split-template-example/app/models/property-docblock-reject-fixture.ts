/**
 * Pins ModelAttributeResolver::isStrictlyMoreStructured()'s reject direction: `meta_info` casts
 * to AsArrayObject (Record<string, unknown>) — vague, but not "entirely" vague (not one of the
 * four hardcoded literals) — so the class's own @property tag, whose `array<string, array>`
 * generic resolves to the *differently* vague `Record<string, unknown[]>`, must never replace
 * it. Both candidate and current genuinely differ in the emitted string, so acceptance vs.
 * rejection is observable regardless of the nullable `| null` suffix either path would add.
 *
 * @see Workbench\App\Models\PropertyDocblockRejectFixture
 */
export interface PropertyDocblockRejectFixture
{
    id: number;
    tags: string | null;
    related_users: string | null;
    meta_info: Record<string, unknown> | null;
    owner_snapshot: string | null;
    created_at: string | null;
    updated_at: string | null;
}
