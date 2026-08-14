<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Workbench\App\Models\Order;
use Workbench\App\Services\UrlService;

/**
 * Regression fixture: a #[TsType(import: …)] token must reach the emitted file together with its
 * import from every result collector, not only analyzeReturnArray(). Each shape below reaches a
 * different collector and used to emit its token with no import at all (TS2304); each uses a
 * distinct #[TsType] class so no shape can ride on another's import.
 *
 * @mixin Order
 */
class CustomImportChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // analyzeInlineArray(): the token is spelled inside the inline object type.
            'inline_meta' => ['cfg' => UrlService::menuSettings()],
            // extractPropertiesFromArray(), reached through $this->merge([...]).
            $this->merge(['merged_meta' => UrlService::pageMeta()]),
            // collectVariableArrayAssignments(), reached through a spread method that builds
            // its array in a local variable and assigns keys onto it.
            ...$this->assignedMeta(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function assignedMeta(): array
    {
        $data = ['assigned_label' => 'meta'];
        $data['assigned_meta'] = UrlService::widgetConfig();

        return $data;
    }
}
