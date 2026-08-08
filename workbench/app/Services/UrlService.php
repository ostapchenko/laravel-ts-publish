<?php

declare(strict_types=1);

namespace Workbench\App\Services;

use RuntimeException;
use Workbench\App\Casts\MenuSettings;
use Workbench\App\Enums\Priority;
use Workbench\App\Enums\Status;
use Workbench\App\Models\Order;

/**
 * Exercises general static-call return type reflection (Task 10):
 * a declared scalar return type, a docblock-only return type (shadowed here by
 * the native `array` hint, since native types win over docblocks), a directly
 * returned enum, and a directly returned model — the latter proves that
 * acceptReflectedTypeInfo() degrades an unpublished class token to unknown
 * rather than emit a type with no import.
 *
 * Also exercises acceptReflectedTypeInfo()'s remaining reject paths, each
 * previously unproven by a fixture-backed test: a #[TsType(import: ...)]-annotated
 * class (its import lives only in customImports, which the general-reflection
 * branch has no dispatch path for), a multi-enum union (directEnumFqcn is a
 * single slot — plumbing only enumFqcns[0] would silently drop the rest), and
 * void/never/mixed return types (which produce syntactically valid but
 * semantically nonsense property types if accepted as-is).
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

    public static function menuSettings(): MenuSettings
    {
        return new MenuSettings;
    }

    public static function statusOrPriority(): Status|Priority
    {
        return Status::Draft;
    }

    public static function voidReturn(): void {}

    public static function neverReturn(): never
    {
        throw new RuntimeException('never returns');
    }

    public static function mixedReturn(): mixed
    {
        return null;
    }

    /**
     * A one-enum-plus-one-class union — classFqcns and enumFqcns are both non-empty for
     * this single TypeScriptTypeInfo. Proves the classFqcns guard fires even when an
     * enumFqcns entry is also present, instead of the enum branch accepting first and
     * silently dropping the Order import.
     */
    public static function orderOrStatus(): Order|Status
    {
        return Status::Draft;
    }
}
