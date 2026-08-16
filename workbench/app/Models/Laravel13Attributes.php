<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Exercises Laravel 13's #[Table], #[Hidden] and #[Appends] class attributes.
 */
#[Table('l13_attribute_fixtures')]
#[Hidden('secret_token')]
#[Appends('label')]
class Laravel13Attributes extends Model
{
    /** A computed accessor published as an append only because #[Appends] adds it to getAppends(). */
    protected function label(): Attribute
    {
        return Attribute::make(
            get: fn (): string => 'Fixture label',
        );
    }
}
