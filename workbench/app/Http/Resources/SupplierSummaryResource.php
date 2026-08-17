<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Supplier;

/**
 * The resource SupplierCollection actually collects — must win over the bare SupplierResource
 * fallback when toResourceCollection() resolves a Supplier[] collection by naming convention.
 *
 * @mixin Supplier
 */
class SupplierSummaryResource extends JsonResource
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
