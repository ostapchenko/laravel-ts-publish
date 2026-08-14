<?php

declare(strict_types=1);

namespace Workbench\App\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A value object implementing Arrayable with typed public properties and no `@return array{...}`
 * shape docblock, used as a fixture for property-based shape inference.
 *
 * @implements Arrayable<string, mixed>
 */
final class ArrayableData implements Arrayable
{
    public function __construct(
        public string $title = '',
        public ?int $weight = null,
    ) {}

    public function toArray(): array
    {
        return ['title' => $this->title, 'weight' => $this->weight];
    }
}
