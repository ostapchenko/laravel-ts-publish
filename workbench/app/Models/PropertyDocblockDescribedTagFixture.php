<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Workbench\App\Concerns\HasDescribedPropertyTag;

/**
 * Exercises the `$`-less `@property` tag's no-description restriction: `list` must not be bound
 * to a bogus type resolved from the trailing description of a different (`$`-less) tag.
 */
class PropertyDocblockDescribedTagFixture extends Model
{
    use HasDescribedPropertyTag;

    protected $table = 'property_docblock_fixtures';
}
