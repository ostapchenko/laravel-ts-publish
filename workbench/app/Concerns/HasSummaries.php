<?php

declare(strict_types=1);

namespace Workbench\App\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;
use Workbench\App\Models\Admin\Store;

/**
 * Fixture: the accessor docblock references Store, imported ONLY here — resolution must use the
 * trait file's use statements, not the consuming class's (Order is in a different namespace and
 * does not import Store, so the same-namespace fallback cannot accidentally rescue this).
 */
trait HasSummaries
{
    /** @return Attribute<Collection<int, Store>, never> */
    protected function summaryItems(): Attribute
    {
        return Attribute::get(fn (): Collection => collect());
    }
}
