<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Supplier;

/**
 * The bare guessed-resource fallback for Supplier — deliberately present so the
 * {Guessed}Collection-first ordering test is non-vacuous: this class must lose to
 * SupplierCollection (which collects SupplierSummaryResource, not this one).
 *
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
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
