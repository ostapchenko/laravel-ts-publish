<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class OrderItemCollection extends ResourceCollection
{
    public $collects = OrderItemResource::class;
}
