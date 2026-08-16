<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Team;

/**
 * toArray() returns a method call directly rather than spreading it into an array literal, and that
 * method in turn returns another — the transitive case.
 *
 * @mixin Team
 */
class BareMethodReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->data();
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->nested();
    }

    /**
     * @return array<string, mixed>
     */
    public function nested(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
        ];
    }
}
