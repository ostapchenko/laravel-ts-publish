<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use AbeTwoThree\LaravelTsPublish\Attributes\TsExclude;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The `{Guessed}Collection` half of the unpublished-guess fixture, also held out of the
 * published set with #[TsExclude]. toResourceCollection() tries this class before the bare
 * AttachmentResource, so MerchantResource::$unpublished_guess_collection only degrades to
 * `unknown` when BOTH convention branches consult PublishedResourceRegistry — dropping either
 * gate alone lets the property resolve again.
 */
#[TsExclude]
class AttachmentCollection extends ResourceCollection
{
    /**
     * @var class-string
     */
    public $collects = AttachmentResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }
}
