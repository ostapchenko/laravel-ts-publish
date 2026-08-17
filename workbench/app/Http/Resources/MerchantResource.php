<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use AbeTwoThree\LaravelTsPublish\Attributes\TsResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Merchant;

/**
 * Exercises Model::toResource() / Collection::toResourceCollection() resolution: naming
 * convention, #[UseResource], explicit arguments, the unresolvable negative cases, and
 * (registrar/registrars/suppliers) the three resolution orderings against a losing
 * candidate that also exists, so an inverted order would visibly fail.
 *
 * @mixin Merchant
 */
#[TsResource(model: Merchant::class)]
class MerchantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_via_closure' => $this->whenLoaded('owner', fn ($m) => $m->toResource()),
            'owner_explicit' => $this->whenLoaded('owner', fn ($m) => $m->toResource(UserResource::class)),
            'owner_direct' => $this->owner->toResource(),
            'staff_via_closure' => $this->whenLoaded('staff', fn ($rows) => $rows->toResourceCollection()),
            'staff_explicit' => $this->whenLoaded('staff', fn ($rows) => $rows->toResourceCollection(UserResource::class)),
            'history_event' => $this->whenLoaded('historyEvent', fn ($m) => $m->toResource()),
            'filing' => $this->whenLoaded('filing', fn ($m) => $m->toResource()),
            'alert' => $this->whenLoaded('alert', fn ($m) => $m->toResource()),
            'registrar' => $this->whenLoaded('registrar', fn ($m) => $m->toResource()),
            'registrars' => $this->whenLoaded('registrars', fn ($rows) => $rows->toResourceCollection()),
            'suppliers' => $this->whenLoaded('suppliers', fn ($rows) => $rows->toResourceCollection()),
        ];
    }
}
