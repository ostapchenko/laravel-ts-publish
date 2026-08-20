<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

/** Carries only #[RouteKey] — no getRouteKeyName()/getKeyName()/$primaryKey override. */
#[RouteKey('slug')]
class AttributeRouteKeyPost extends Model
{
    protected $table = 'posts';
}
