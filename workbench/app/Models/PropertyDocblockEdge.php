<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exercises the guardrails of ModelAttributeResolver::refineWithPropertyDocblock():
 *
 * - `related_users` has only a `@property-write` tag, which describes a setter
 *   type and must never be used to type a readable property.
 * - `meta_info` has no matching tag at all — the `@property $meta` tag below is
 *   a shorter, unrelated name and must not match a longer column name it
 *   happens to prefix.
 * - `owner_snapshot` has a real `@property-read` tag naming a Model class,
 *   proving the refinement produces a correctly-imported class token — not
 *   just scalars and generic containers — and that `-read` is accepted
 *   alongside the bare `@property` tag.
 *
 * @property-write array<string, string> $related_users
 * @property array<string, string>|null $meta
 * @property-read User|null $owner_snapshot
 */
class PropertyDocblockEdge extends Model
{
    protected $table = 'property_docblock_fixtures';

    protected function casts(): array
    {
        return [
            'related_users' => 'array',
            'meta_info' => 'array',
            'owner_snapshot' => 'array',
        ];
    }
}
