<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Post;

/**
 * Exercises a morphTo reached through a relation filter, where the union lands inside an inline shape.
 *
 * `attachment_hidden` exercises the ts-publish.models.exclude_hidden coupling: Attachment::$hidden
 * keeps `internal_notes` out of the emitted model interface only when exclude_hidden is enabled, and
 * `Pick<Attachment, 'internal_notes'>` would then violate `K extends keyof T` (TS2344) — the reference
 * must degrade to inline expansion in that case. With the default (exclude_hidden disabled), the
 * column is published and the Pick<> reference is preferred, matching Model::only()'s runtime result
 * either way.
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
            // Control: every key is published, so the Pick<> reference is still preferred.
            'attachment_public' => $this->attachment->only(['id', 'filename']),
            'attachment_hidden' => $this->attachment->only(['id', 'internal_notes']),
        ];
    }
}
