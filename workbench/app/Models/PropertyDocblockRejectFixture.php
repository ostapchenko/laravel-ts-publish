<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;

/**
 * Pins ModelAttributeResolver::isStrictlyMoreStructured()'s reject direction: `meta_info` casts
 * to AsArrayObject (Record<string, unknown>) — vague, but not "entirely" vague (not one of the
 * four hardcoded literals) — so the class's own @property tag, whose `array<string, array>`
 * generic resolves to the *differently* vague `Record<string, unknown[]>`, must never replace
 * it. Both candidate and current genuinely differ in the emitted string, so acceptance vs.
 * rejection is observable regardless of the nullable `| null` suffix either path would add.
 *
 * @property array<string, array>|null $meta_info
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
