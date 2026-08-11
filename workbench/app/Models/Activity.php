<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Workbench\App\Concerns\HasRelatableLinkedRecord;

/**
 * Fixture: two morphTos on one model. `causer` (trait-provided, see HasRelatableLinkedRecord) is
 * narrowed by its docblock generic; `subject` is left bare and resolves via the reverse map —
 * no model declares a reverse relation for either name, so `subject` stays unknown.
 */
class Activity extends Model
{
    use HasRelatableLinkedRecord;

    protected $table = 'activities';

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
