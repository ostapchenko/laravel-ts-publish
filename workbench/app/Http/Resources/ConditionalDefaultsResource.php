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

            // hasExplicitDefaultArg() contract: an explicit `null` at the default position still counts —
            // Laravel distinguishes it from an omitted argument via func_num_args(), not `=== null`.
            'not_null_explicit_null_default' => $this->whenNotNull($this->full_address, null),

            // A named argument makes position meaningless, so hasExplicitDefaultArg() bails to `false` —
            // this behaves as if no default were passed at all (optional, value arm only).
            'not_null_named_default' => $this->whenNotNull($this->full_address, default: 0),

            // Same bail-out for a spread argument at the default position.
            'not_null_spread_default' => $this->whenNotNull($this->full_address, ...[0]),
        ];
    }
}
