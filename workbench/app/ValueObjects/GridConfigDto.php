<?php

declare(strict_types=1);

namespace Workbench\App\ValueObjects;

/** @phpstan-type GridConfig array{filters?: array<string, mixed>, sorts?: list<string>, columns?: list<string>} */
final readonly class GridConfigDto
{
    public function __construct(
        public string $label,
        /** @var GridConfig */
        public array $config,
    ) {}
}
