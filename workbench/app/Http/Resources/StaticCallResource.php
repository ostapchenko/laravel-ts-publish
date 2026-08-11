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
            // Proves acceptReflectedTypeInfo() plumbs a directly-returned Model's FQCN
            // through the modelFqcn slot, importing the model interface.
            'located_order' => UrlService::locateOrder(1),
            // new SomeCollection(...) — mirrors the ::make() case above via the same
            // collectedResourceClass() helper, from analyzeNewResource() this time.
            'new_items' => new OrderItemCollection($this->items),
            // Proves acceptReflectedTypeInfo() carries a #[TsType(import: ...)]-annotated
            // class return's import through the new customImports slot.
            'menu_settings' => UrlService::menuSettings(),
            // Proves acceptReflectedTypeInfo() plumbs a multi-enum union return through
            // embeddedEnumFqcns, since directEnumFqcn is a single slot.
            'status_or_priority' => UrlService::statusOrPriority(),
            // Proves acceptReflectedTypeInfo() degrades void/never/mixed returns instead
            // of emitting syntactically-valid-but-nonsense property types.
            'void_return' => UrlService::voidReturn(),
            'never_return' => UrlService::neverReturn(),
            'mixed_return' => UrlService::mixedReturn(),
            // Proves acceptReflectedTypeInfo() plumbs a model+enum union through both
            // embeddedModelFqcns and embeddedEnumFqcns off the same result.
            'order_or_status' => UrlService::orderOrStatus(),
            // Proves acceptReflectedTypeInfo() still rejects a non-Model class result:
            // no published file exists to import OpaqueHandle from.
            'money_value' => UrlService::moneyValue(),
        ];
    }
}
