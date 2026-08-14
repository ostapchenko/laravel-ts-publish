<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Team;

/**
 * Two methods that spread each other. Without a visited-method guard this recurses until the
 * parser exhausts memory; with one it degrades to an empty analysis.
 *
 * @mixin Team
 */
class MutuallyRecursiveSpreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            ...$this->alpha(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function alpha(): array
    {
        return [...$this->beta()];
    }

    /**
     * @return array<string, mixed>
     */
    public function beta(): array
    {
        return [...$this->alpha()];
    }
}
