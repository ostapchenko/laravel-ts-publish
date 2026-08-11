<?php

declare(strict_types=1);

namespace Workbench\App\ValueObjects;

use AbeTwoThree\LaravelTsPublish\Attributes\TsType;

/**
 * A #[TsType(import: ...)]-annotated class distinct from both MenuSettings and PageMeta, so the
 * coalesce customImports-merge regression test is isolated from the other two fixtures' own
 * contributions to the shared analysis-level customImports map.
 */
#[TsType(['type' => 'WidgetConfigType', 'import' => '@js/types/widget-config'])]
class WidgetConfig
{
    public function __construct(
        public string $variant = '',
    ) {}
}
