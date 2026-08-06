import type { User } from '.';

/**
 * Exercises the guardrails of ModelAttributeResolver::refineWithPropertyDocblock():
 *
 * - `related_users` has only a `@property-write` tag, which describes a setter
 * type and must never be used to type a readable property. Its own docblock
 * line also carries a description mentioning `$related_users` a second time
 * (see `label` below) — the real tag for `related_users` must still be the
 * one found, not a description bleeding across from a different tag.
 * - `meta_info` has no matching tag at all — the `@property $meta` tag below is
 * a shorter, unrelated name and must not match a longer column name it
 * happens to prefix.
 * - `owner_snapshot` has a real `@property-read` tag naming a Model class,
 * proving the refinement produces a correctly-imported class token — not
 * just scalars and generic containers — and that `-read` is accepted
 * alongside the bare `@property` tag.
 * - `label` is not a real column; its tag exists only so its own description
 * text ("...the $related_users value") sits next to a *different* tag's
 * variable name in the same docblock. A type capture that isn't anchored
 * to stop at `$` could walk straight through `$label`'s own marker and the
 * whole description, then wrongly claim it as `related_users`'s type.
 * - `tags` is cast to plain `array` and tagged with an unrecognized generic
 * container (`LengthAwarePaginator` isn't a Collection/array/list type
 * resolveGenericContainerType() knows how to unwrap). The resolved type
 * still contains `<` after name resolution, and must degrade to the
 * pre-existing vague type rather than fall into toTsType()'s partial
 * string matching, where the literal "int" inside would otherwise resolve
 * to `number`.
 *
 * @see Workbench\App\Models\PropertyDocblockEdge
 */
export interface PropertyDocblockEdge
{
    // Columns
    id: number;
    tags: unknown[] | null;
    related_users: unknown[] | null;
    meta_info: unknown[] | null;
    owner_snapshot: User | null;
    created_at: string | null;
    updated_at: string | null;
}
