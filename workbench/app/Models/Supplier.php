<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exercises toResourceCollection()'s naming-convention order: the guessed SupplierCollection
 * class must win over the bare SupplierResource fallback, and since it collects a different
 * resource (SupplierSummaryResource), the two orderings are visibly distinguishable.
 */
class Supplier extends Model {}
