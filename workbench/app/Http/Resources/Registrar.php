<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Registrar as RegistrarModel;

/**
 * The bare naming candidate for the Registrar model — deliberately present so the
 * Resource-suffixed-first ordering test is non-vacuous: this class must lose to
 * RegistrarResource.
 *
 * @mixin RegistrarModel
 */
class Registrar extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
        ];
    }
}
