<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use AbeTwoThree\LaravelTsPublish\Attributes\TsExclude;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Resource-suffixed naming-convention guess for LedgerCollection, held out of the published
 * set with #[TsExclude] so the naming-convention branch must reject it before trying the bare
 * Ledger candidate.
 */
#[TsExclude]
class LedgerResource extends JsonResource
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
