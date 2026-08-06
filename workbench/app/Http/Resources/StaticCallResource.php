<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use AbeTwoThree\LaravelTsPublish\EnumResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Enums\Status;
use Workbench\App\Models\Order;
use Workbench\App\Services\UrlService;

/**
 * Exercises static-call return type reflection and enum static args (Task 10).
 *
 * @mixin Order
 */
class StaticCallResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => UrlService::generateUrl($this->resource),
            'status_badge' => EnumResource::make(Status::defaultCase()),
            'status_const' => EnumResource::make(Status::Published),
            'items' => OrderItemCollection::make($this->items),
            // Proves acceptReflectedTypeInfo() plumbs a directly-returned enum's FQCN.
            'default_status' => UrlService::defaultStatus(),
            // Proves acceptReflectedTypeInfo() degrades a directly-returned, non-enum
            // class (no dispatch path for an import) to unknown rather than emitting
            // a TS token nothing imports.
            'located_order' => UrlService::locateOrder(1),
        ];
    }
}
