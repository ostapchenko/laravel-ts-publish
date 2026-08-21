<?php

declare(strict_types=1);

namespace Workbench\App\ValueObjects;

/**
 * A plain class with no Arrayable/JsonSerializable/__toString, no #[TsType] override and no public
 * properties — proves acceptReflectedTypeInfo() still degrades a non-Model class result to unknown,
 * since this package generates no importable file for it. The property is protected on purpose:
 * toTsType() step 5c inlines a plain class's shape once its public properties are all typed, which
 * would take this fixture off the rejection branch it exists to cover.
 */
class OpaqueHandle
{
    public function __construct(
        protected string $handle = '',
    ) {}
}
