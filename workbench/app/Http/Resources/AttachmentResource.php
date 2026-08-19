<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use AbeTwoThree\LaravelTsPublish\Attributes\TsExclude;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Attachment;

/**
 * The naming-convention guess for Attachment, deliberately kept out of the published set with
 * #[TsExclude] so no .ts file is ever written for it. It stands in for the third-party
 * `Vendor\Pkg\Http\Resources\FooResource` case: class_exists() accepts it, so an ungated
 * convention guess would emit `AttachmentResource` plus an import of a module that does not
 * exist (a TS2307 neither CI gate counts). PublishedResourceRegistry is what rejects it.
 *
 * MerchantResource::$unpublished_guess and $unpublished_guess_collection consume this; see the
 * "published set" tests in ResourceAstAnalyzerTest.php.
 *
 * @mixin Attachment
 */
#[TsExclude]
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
        ];
    }
}
