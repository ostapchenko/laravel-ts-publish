/**
 * Exercises ModelAttributeResolver::refineWithPropertyDocblock()'s trait walk: `labels` carries
 * no tag of its own anywhere in the class/parent chain — only the one declared on the HasLabels
 * trait this class uses.
 *
 * @see Workbench\App\Models\PropertyDocblockTraitFixture
 */
export interface PropertyDocblockTraitFixture
{
    id: number;
    tags: string | null;
    related_users: string | null;
    meta_info: string | null;
    owner_snapshot: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface PropertyDocblockTraitFixtureMutators
{
    /** Old-style accessor whose native `array` return type is vague without the trait's docblock. */
    labels: string[];
}

export interface PropertyDocblockTraitFixtureAll extends PropertyDocblockTraitFixture, PropertyDocblockTraitFixtureMutators {}
