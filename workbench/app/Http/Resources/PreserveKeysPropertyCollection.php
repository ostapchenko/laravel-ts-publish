<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A collection that keeps its source keys, so the payload is a JSON object rather than an array.
 * Uses the property form, which predates the attribute and works on Laravel 12.
 */
class PreserveKeysPropertyCollection extends ResourceCollection
{
    public $preserveKeys = true;

    public $collects = TeamResource::class;
}
