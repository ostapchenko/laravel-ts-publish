<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\UseResourceCollection;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Http\Resources\RegistrarGroupCollection;

/**
 * Exercises two orderings: singular toResource() must prefer the Resource-suffixed naming
 * candidate (RegistrarResource) over the bare one (Registrar), which also exists; and
 * #[UseResourceCollection] must stop hard even when its target's element is undeterminable,
 * never falling through to the RegistrarResource naming-convention guess.
 */
#[UseResourceCollection(RegistrarGroupCollection::class)]
class Registrar extends Model {}
