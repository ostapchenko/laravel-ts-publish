<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\User;

/**
 * Exercises return $this->only([...]) naming a $hidden column explicitly.
 *
 * The property set is exactly the named keys, so it is explicit — exclude_hidden must not
 * drop `password` here, since the caller named it.
 *
 * @mixin User
 */
class UserOnlyHiddenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->only(['id', 'password']);
    }
}
