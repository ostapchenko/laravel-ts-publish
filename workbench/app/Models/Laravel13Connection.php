<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Model;

// Its table exists only on the 'laravel13_secondary' connection, created dynamically by its test
// in ModelTransformerTest.php — proving #[Connection] actually routes the column lookup there.

/**
 * Exercises Laravel 13's #[Connection] class attribute.
 */
#[Connection('laravel13_secondary')]
class Laravel13Connection extends Model {}
