<?php

declare(strict_types=1);

namespace Workbench\App\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-type GridConfig array{filters?: array<string, mixed>, sorts?: list<string>, columns?: list<string>}
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class GridConfigDto implements Arrayable
{
    public function __construct(
        public string $label,
        /** @var GridConfig */
        public array $config,
    ) {}

    /** @return array{label: string, config: array<string, mixed>} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'config' => $this->config];
    }
}
