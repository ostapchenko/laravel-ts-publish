<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

/**
 * Declares neither a toArray() nor a @mixin — pins parent-docblock model inheritance: the model
 * has to come from OrderResource's own `@mixin Order`, since the naming convention would look for
 * a Workbench\App\Models\BodylessOrder that does not exist. Without it every column is unknown.
 */
class BodylessOrderResource extends OrderResource {}
