<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Team;
use Workbench\App\Services\UnrelatedHelperService;

/**
 * outer() returns $this->helper()->wrongCall() — a method call chained off a non-$this receiver. The
 * resource also defines its own wrongCall(), whose properties must not leak in through that chain.
 *
 * @mixin Team
 */
class NonThisReceiverSpreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            ...$this->outer(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function outer(): array
    {
        return $this->helper()->wrongCall();
    }

    protected function helper(): UnrelatedHelperService
    {
        return new UnrelatedHelperService;
    }

    /**
     * @return array<string, mixed>
     */
    protected function wrongCall(): array
    {
        return [
            'leaked' => 'should not resolve through a non-$this receiver',
        ];
    }
}
