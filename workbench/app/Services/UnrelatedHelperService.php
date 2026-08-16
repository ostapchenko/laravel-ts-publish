<?php

declare(strict_types=1);

namespace Workbench\App\Services;

/**
 * A plain, non-resource object returned by NonThisReceiverSpreadResource::helper(), used to prove a
 * method call chained off a non-$this receiver isn't mistaken for $this->wrongCall().
 */
class UnrelatedHelperService
{
    /**
     * @return array<string, mixed>
     */
    public function wrongCall(): array
    {
        return [
            'unrelated' => 'this belongs to the service, not the resource',
        ];
    }
}
