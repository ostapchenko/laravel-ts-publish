<?php

declare(strict_types=1);

namespace Workbench\App\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A value object implementing Arrayable whose toArray() carries an array-shape
 * docblock, used as a fixture for step 5a inline object shape inference.
 *
 * @implements Arrayable<string, int|string>
 */
class Money implements Arrayable
{
    public function __construct(
        public int $amount = 0,
        public string $currency = 'USD',
    ) {}

    /** @return array{amount: int, currency: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}
