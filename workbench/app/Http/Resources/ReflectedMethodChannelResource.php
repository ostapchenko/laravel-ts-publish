<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Enums\Status;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;

/**
 * Regression fixture: analyzeThisMethodCall() spread a reflected TypeScriptTypeInfo straight into its
 * result, whose enumFqcns/classFqcns keys no dispatcher reads — so both properties emitted a token
 * with no import (TS2304). The reflection now goes through acceptReflectedTypeInfo() like every other
 * reflected path. Both methods are `: mixed` so the @return docblock is what resolves them.
 *
 * @mixin Order
 */
class ReflectedMethodChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'fallback_status' => $this->fallbackStatus(),
            'fallback_owner' => $this->fallbackOwner(),
        ];
    }

    /**
     * @return Status
     */
    protected function fallbackStatus(): mixed
    {
        return Status::Draft;
    }

    /**
     * @return User
     */
    protected function fallbackOwner(): mixed
    {
        return new User;
    }
}
