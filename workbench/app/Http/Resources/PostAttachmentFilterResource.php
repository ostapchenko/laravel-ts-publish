<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Post;

/**
 * Exercises a morphTo reached through a relation filter, where the union lands inside an inline shape.
 *
 * @mixin Post
 */
class PostAttachmentFilterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attachment' => $this->attachment->only(['id', 'filename', 'attachable']),
        ];
    }
}
