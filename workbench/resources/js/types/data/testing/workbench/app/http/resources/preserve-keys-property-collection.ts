import type { TeamResource } from '.';

/**
 * A collection that keeps its source keys, so the payload is a JSON object rather than an array.
 * Uses the property form, which predates the attribute and works on Laravel 12.
 *
 * @see Workbench\App\Http\Resources\PreserveKeysPropertyCollection
 */
export interface PreserveKeysPropertyCollection
{
    data: Record<string, TeamResource>;
}
