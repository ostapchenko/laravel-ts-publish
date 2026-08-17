<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use AbeTwoThree\LaravelTsPublish\EnumResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Enums\Status;
use Workbench\App\Models\Order;
use Workbench\App\Services\ChannelDefaults;

/**
 * Regression fixture for Task 17A: SomeClass::CONSTANT as a property value resolves to a real TS
 * type via reflection instead of unknown. Also pins that `Foo::class` and an enum case reached
 * through EnumResource::make() keep resolving exactly as before alongside the new feature.
 *
 * @mixin Order
 */
class ClassConstantResource extends JsonResource
{
    protected const int SCHEMA_VERSION = 2;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Array constant with a nested array — the eaglesys OWNER_MINIMUM_CHANNELS shape.
            'owner_minimum_channels' => ChannelDefaults::DEFAULT_CHANNELS,
            // Scalar constant (int).
            'max_retries' => ChannelDefaults::MAX_RETRIES,
            // self::CONSTANT referenced from within the resource.
            'schema_version' => self::SCHEMA_VERSION,
            // Reached through ?? — the left arm is an undefined model attribute (unknown).
            'fallback_channels' => $this->totally_unmapped_field ?? ChannelDefaults::DEFAULT_CHANNELS,
            // Negative case: a plain-list constant analyzeInlineArray() can't shape — stays unknown.
            'channel_tags' => ChannelDefaults::CHANNEL_TAGS,
            // Regression pin: `Foo::class` still types as a plain string, not a resource/model type.
            'resource_marker' => self::class,
            // Regression pin: an enum case reached through EnumResource::make() is unaffected.
            'status_marker' => EnumResource::make(Status::Draft),
        ];
    }
}
