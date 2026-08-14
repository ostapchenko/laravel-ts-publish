<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Address;

/**
 * Exercises the conditional family's third default argument. An explicit default means the key can
 * never be missing, so the property is required and its type unions both arms.
 *
 * @mixin Address
 */
class ConditionalDefaultsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'not_null_no_default' => $this->whenNotNull($this->full_address),
            'not_null_with_default' => $this->whenNotNull($this->full_address, 0),
            'not_null_same_type_default' => $this->whenNotNull($this->latitude, 0),
            'null_with_default' => $this->whenNull($this->full_address, 'absent'),
        ];
    }
}
