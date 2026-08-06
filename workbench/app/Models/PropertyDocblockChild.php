<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * Child of PropertyDocblockBase that redeclares `@property` for the same
 * `tags` column with a different shape. Proves refineWithPropertyDocblock()
 * walks the reflection chain child-first — this class's own tag must win
 * over the parent's, not merely be found alongside it.
 *
 * @property array<int, string>|null $tags
 */
class PropertyDocblockChild extends PropertyDocblockBase {}
