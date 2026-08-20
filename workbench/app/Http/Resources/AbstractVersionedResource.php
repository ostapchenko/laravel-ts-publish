<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base resource for ClassConstantResource: exists purely so a child can read `parent::CONSTANT`.
 */
abstract class AbstractVersionedResource extends JsonResource
{
    protected const int BASE_VERSION = 1;
}
