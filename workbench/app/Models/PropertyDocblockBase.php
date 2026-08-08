<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base fixture for ModelAttributeResolver::refineWithPropertyDocblock()'s
 * parent-class walk. `tags` casts to plain `array` (vague on its own); this
 * class's own `@property` tag refines it to a typed Record.
 *
 * @property array<string, string>|null $tags
 */
class PropertyDocblockBase extends Model
{
    protected $table = 'property_docblock_fixtures';

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }
}
