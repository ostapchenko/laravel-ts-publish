<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The guessed {Supplier}Collection class — must be tried before the bare SupplierResource
 * fallback, and collects SupplierSummaryResource rather than SupplierResource so the two
 * possible orderings produce visibly different element types.
 */
class SupplierCollection extends ResourceCollection
{
    /**
     * @var class-string
     */
    public $collects = SupplierSummaryResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }
}
