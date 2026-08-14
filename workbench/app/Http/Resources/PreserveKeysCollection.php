<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Resources\Attributes\PreserveKeys;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A collection that keeps its source keys, so the payload is a JSON object rather than an array.
 * Uses Laravel 13's #[PreserveKeys] attribute.
 */
#[PreserveKeys]
class PreserveKeysCollection extends ResourceCollection
{
    public $collects = TeamResource::class;
}
