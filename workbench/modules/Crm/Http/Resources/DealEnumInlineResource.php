<?php

declare(strict_types=1);

namespace Workbench\Crm\Http\Resources;

use AbeTwoThree\LaravelTsPublish\EnumResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\Crm\Models\Deal;

/**
 * Exercises two same-named enum consts reachable only through an inline EnumResource
 * wrap, with no top-level reader of either enum anywhere else in the file.
 *
 * @mixin Deal
 */
class DealEnumInlineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'summary' => [
                'app_status' => EnumResource::make($this->status),
                'crm_status' => EnumResource::make($this->crm_status),
            ],
        ];
    }
}
