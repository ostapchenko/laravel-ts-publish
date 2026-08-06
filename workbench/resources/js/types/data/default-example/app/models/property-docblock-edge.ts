import type { User } from '.';

/**
 * Exercises the guardrails of ModelAttributeResolver::refineWithPropertyDocblock():
 *
 * - `related_users` has only a `@property-write` tag, which describes a setter
 * type and must never be used to type a readable property.
 * - `meta_info` has no matching tag at all — the `@property $meta` tag below is
 * a shorter, unrelated name and must not match a longer column name it
 * happens to prefix.
 * - `owner_snapshot` has a real `@property-read` tag naming a Model class,
 * proving the refinement produces a correctly-imported class token — not
 * just scalars and generic containers — and that `-read` is accepted
 * alongside the bare `@property` tag.
 *
 * @see Workbench\App\Models\PropertyDocblockEdge
 */
export interface PropertyDocblockEdge
{
    id: number;
    tags: string | null;
    related_users: unknown[] | null;
    meta_info: unknown[] | null;
    owner_snapshot: User | null;
    created_at: string | null;
    updated_at: string | null;
}
