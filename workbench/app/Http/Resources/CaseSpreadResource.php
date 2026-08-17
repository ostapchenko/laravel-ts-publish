<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Post;

/**
 * A spread whose call-site casing differs from the declared method. PHP method calls are
 * case-insensitive, so this is valid, runnable code the analyzer must still resolve.
 *
 * @mixin Post
 */
class CaseSpreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            ...$this->Extras(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extras(): array
    {
        return [
            'case_title' => $this->title,
        ];
    }
}
