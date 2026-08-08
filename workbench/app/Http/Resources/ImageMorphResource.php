<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Image;

/**
 * Exercises a morphTo relation exposed through a resource: the emitted union needs every parent imported.
 *
 * @mixin Image
 */
class ImageMorphResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'imageable' => $this->imageable,
            'uploaders_from_docblock' => $this->uploaders_from_docblock,
            'imageable_when_loaded' => $this->whenLoaded('imageable'),
        ];
    }
}
