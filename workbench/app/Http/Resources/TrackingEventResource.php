<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\TrackingEvent;

/**
 * The naming-convention candidate for Workbench\App\Models\TrackingEvent — deliberately present so
 * the #[UseResource(EventLogResource::class)] precedence test is non-vacuous: this class must lose.
 *
 * @mixin TrackingEvent
 */
class TrackingEventResource extends JsonResource
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
