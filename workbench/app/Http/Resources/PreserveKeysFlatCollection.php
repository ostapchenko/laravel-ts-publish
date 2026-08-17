<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Key-preserving AND flat ($wrap = null): the paginated Inertia prop must emit a keyed
 * record data member, not JsonResourcePaginator's array one. Property form, so the fixture
 * behaves identically on Laravel 12.
 */
class PreserveKeysFlatCollection extends ResourceCollection
{
    /** @var string|null Disable wrapping so the paginator's data is the keyed record itself */
    public static $wrap = null;

    public $preserveKeys = true;

    public $collects = TeamResource::class;
}
