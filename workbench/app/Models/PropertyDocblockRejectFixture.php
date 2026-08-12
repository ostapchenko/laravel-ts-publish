<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;

/**
 * Pins ModelAttributeResolver::isStrictlyMoreStructured()'s reject direction: `meta_info` casts
 * to AsArrayObject (Record<string, unknown>) — already more structured than a bare untyped
 * array/collection, not "entirely" vague — so the class's own @property tag, which names a
 * vaguer bare array, must never replace it.
 *
 * @property array|null $meta_info
 */
class PropertyDocblockRejectFixture extends Model
{
    protected $table = 'property_docblock_fixtures';

    protected function casts(): array
    {
        return [
            'meta_info' => AsArrayObject::class,
        ];
    }
}
