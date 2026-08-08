<?php

declare(strict_types=1);

namespace Workbench\App\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Custom MorphOne subclass — mirrors apps that wrap morph relations
 * (e.g. a File attachment relation with helper methods).
 *
 * @extends MorphOne<Model, Model>
 */
class MorphOneAttachment extends MorphOne {}
