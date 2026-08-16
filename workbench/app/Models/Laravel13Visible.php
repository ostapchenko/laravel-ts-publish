<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Model;

// Kept off the #[Hidden] fixture and every resource fixture: #[Visible] is an allowlist, so
// combining it with either would strip nearly every column and make those assertions meaningless.

/**
 * Exercises Laravel 13's #[Visible] class attribute (an allowlist).
 */
#[Visible('name')]
class Laravel13Visible extends Model {}
