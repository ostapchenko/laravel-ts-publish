<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Workbench\App\Models\Team;

/**
 * Singular resource whose ::collection() preserves keys via $preserveKeys — exercises the
 * anonymous (static-collection) Inertia path. Delegates to TeamResource's toArray() shape.
 *
 * @mixin Team
 */
class PreserveKeysTeamResource extends TeamResource
{
    public $preserveKeys = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
