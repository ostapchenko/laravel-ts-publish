<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\User;

/**
 * Exercises return $this->except([...]) against a model with $hidden columns.
 *
 * The property set is derived from every model attribute minus the named keys, so it is
 * implicit — exclude_hidden must drop $hidden columns here even though none are named.
 *
 * @mixin User
 */
class UserExceptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->except(['id']);
    }
}
