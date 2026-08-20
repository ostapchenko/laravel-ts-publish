<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Workbench\App\Models\Team;

/**
 * Declares no toArray() of its own — pins that the analyzer walks up to the nearest ancestor
 * that does, rather than emitting an empty interface. Carries its own @mixin.
 *
 * @mixin Team
 */
class BodylessTeamResource extends TeamResource {}
