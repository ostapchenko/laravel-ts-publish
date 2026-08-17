<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Registrar;

/**
 * The Resource-suffixed naming candidate for Registrar — must win over the bare Registrar
 * resource below, since Model::guessResourceName() tries the suffixed name first.
 *
 * @mixin Registrar
 */
class RegistrarResource extends JsonResource
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
