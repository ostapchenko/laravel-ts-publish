<?php

declare(strict_types=1);

namespace Workbench\App\Concerns;

/**
 * Fixture: a class-level `@property` tag missing its `$` sigil, exactly as found in a real vendor
 * trait — ModelAttributeResolver::refineWithPropertyDocblock()'s tag regex must still match it.
 *
 * @property string[] labels
 */
trait HasLabels
{
    /** Old-style accessor whose native `array` return type is vague without the trait's docblock. */
    public function getLabelsAttribute(): array // @phpstan-ignore missingType.parameter
    {
        return [];
    }
}
