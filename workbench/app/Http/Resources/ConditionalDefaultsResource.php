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

            'when_no_default' => $this->when($this->id > 0, $this->full_address),

            // A differently-typed default makes the union observable: the merged type must be
            // `string | number`, not just `string` — a same-typed default can't tell union logic
            // apart from a no-op, since deduping collapses it back to a single member either way.
            'when_with_default' => $this->when($this->id > 0, $this->full_address, 0),

            'has_with_default' => $this->whenHas('full_address', $this->full_address, 'fallback'),
            'loaded_with_default' => $this->whenLoaded('user', fn ($user) => $user, null),
            'counted_with_default' => $this->whenCounted('user', null, 0),

            // whenAggregated's default sits at index 4 — the riskiest index in the family, since it's
            // the only one not at position 2 or 3. Neither this handler nor the pivot ones below inspect
            // the relation/table/aggregate arguments at all (their type is a fixed 'number'/'unknown'),
            // so no real aggregate or pivot relation needs to exist on the model for this to be meaningful.
            'aggregated_no_default' => $this->whenAggregated('items', 'price', 'sum', null),
            'aggregated_with_default' => $this->whenAggregated('items', 'price', 'sum', null, 0),

            // whenPivotLoaded's default sits at index 2.
            'pivot_loaded_no_default' => $this->whenPivotLoaded('team_user', null),
            'pivot_loaded_with_default' => $this->whenPivotLoaded('team_user', null, 0),

            // whenPivotLoadedAs's default sits at index 3 — one higher than whenPivotLoaded, because of
            // its leading $accessor argument.
            'pivot_loaded_as_no_default' => $this->whenPivotLoadedAs('membership', 'team_user', null),
            'pivot_loaded_as_with_default' => $this->whenPivotLoadedAs('membership', 'team_user', null, 0),
        ];
    }
}
