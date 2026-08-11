<?php

declare(strict_types=1);

namespace Workbench\App\ValueObjects;

/**
 * A plain class with no Arrayable/JsonSerializable/__toString and no #[TsType] override —
 * proves acceptReflectedTypeInfo() still degrades a non-Model class result to unknown, since
 * this package generates no importable file for it.
 */
class OpaqueHandle
{
    public function __construct(
        public string $handle = '',
    ) {}
}
