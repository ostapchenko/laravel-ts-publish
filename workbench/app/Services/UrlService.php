<?php

declare(strict_types=1);

namespace Workbench\App\Services;

use Workbench\App\Enums\Status;
use Workbench\App\Models\Order;

/**
 * Exercises general static-call return type reflection (Task 10):
 * a declared scalar return type, a docblock-only return type (shadowed here by
 * the native `array` hint, since native types win over docblocks), a directly
 * returned enum, and a directly returned model — the latter proves that
 * acceptReflectedTypeInfo() degrades an unpublished class token to unknown
 * rather than emit a type with no import.
 */
class UrlService
{
    public static function generateUrl(Order $order): string
    {
        return '/orders/'.$order->getKey();
    }

    /** @return array{id: int, url: string} */
    public static function urlPayload(Order $order): array
    {
        return ['id' => (int) $order->getKey(), 'url' => self::generateUrl($order)];
    }

    public static function defaultStatus(): Status
    {
        return Status::Draft;
    }

    public static function locateOrder(int $id): Order
    {
        return Order::query()->findOrFail($id);
    }
}
