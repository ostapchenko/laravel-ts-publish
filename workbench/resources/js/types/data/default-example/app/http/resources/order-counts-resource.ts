/**
 * Exercises withCount()/withExists() virtual attributes and camelCase
 * attribute access, both resolved via ModelAttributeResolver's fallbacks
 * rather than a literal attribute match.
 *
 * @see Workbench\App\Http\Resources\OrderCountsResource
 */
export interface OrderCountsResource
{
    items_count: number;
    items_exists: boolean;
    formatted_total_camel: string;
}
