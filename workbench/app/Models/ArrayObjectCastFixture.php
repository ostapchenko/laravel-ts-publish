<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;

/**
 * Pins the As*ArrayObject cast family's `unknown[] | Record<string, unknown>` map entry in
 * generated output. Reuses the property_docblock_fixtures table's unused owner_snapshot column.
 */
class ArrayObjectCastFixture extends Model
{
    protected $table = 'property_docblock_fixtures';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_snapshot' => AsArrayObject::class,
        ];
    }
}
