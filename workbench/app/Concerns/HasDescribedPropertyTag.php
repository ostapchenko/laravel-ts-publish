<?php

declare(strict_types=1);

namespace Workbench\App\Concerns;

/**
 * Regression fixture for the `$`-less `@property` tag's safety guarantee: this tag carries a
 * trailing description whose last word ("list") coincides with a real, unrelated accessor on
 * the consuming model. Before the no-description restriction, the tag regex could mistake that
 * trailing description word for the tag's own property name, binding it to garbled "type" text
 * pulled from the description — see ModelAttributeResolver::refineFromClassDocblock().
 *
 * @property string[] tag_names Friendly labels list
 */
trait HasDescribedPropertyTag
{
    /** Old-style accessor named after the trailing word of this trait's own $-less tag's description. */
    public function getListAttribute(): array // @phpstan-ignore missingType.parameter
    {
        return [];
    }
}
