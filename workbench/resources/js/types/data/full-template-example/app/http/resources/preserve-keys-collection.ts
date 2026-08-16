import type { TeamResource } from '.';

/**
 * A collection that keeps its source keys, so the payload is a JSON object rather than an array.
 * Uses Laravel 13's #[PreserveKeys] attribute.
 *
 * @see Workbench\App\Http\Resources\PreserveKeysCollection
 */
export interface PreserveKeysCollection
{
    data: Record<string, TeamResource>;
}
