<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Attributes;

use Attribute;

/**
 * Attribute to specify that an enum method should be included when generating TypeScript types.
 *
 * The method is called once per case; the results are emitted as an object keyed by case name.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class TsEnumMethod
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $name = '',
        public string $description = '',
        public array $params = [],
    ) {}
}
