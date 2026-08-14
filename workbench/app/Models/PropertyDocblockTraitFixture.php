<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Workbench\App\Concerns\HasLabels;

/**
 * Exercises ModelAttributeResolver::refineWithPropertyDocblock()'s trait walk: `labels` carries
 * no tag of its own anywhere in the class/parent chain — only the one declared on the HasLabels
 * trait this class uses.
 */
class PropertyDocblockTraitFixture extends Model
{
    use HasLabels;

    protected $table = 'property_docblock_fixtures';
}
