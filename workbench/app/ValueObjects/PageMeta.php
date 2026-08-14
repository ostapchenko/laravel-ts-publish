<?php

declare(strict_types=1);

namespace Workbench\App\ValueObjects;

use AbeTwoThree\LaravelTsPublish\Attributes\TsType;

/**
 * A #[TsType(import: ...)]-annotated class distinct from Workbench\App\Casts\MenuSettings, so the
 * ternary customImports-merge regression test is isolated from the plain `menu_settings`
 * property's contribution to the shared analysis-level customImports map.
 */
#[TsType(['type' => 'PageMetaType', 'import' => '@js/types/page-meta'])]
class PageMeta
{
    public function __construct(
        public string $title = '',
    ) {}
}
