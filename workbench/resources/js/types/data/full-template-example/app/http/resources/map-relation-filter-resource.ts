import type { User } from '../../models';

/**
 * Regression pin: `$this->map->only([...])` must route through the relation-filter guard, not
 * Laravel's `->map->only()` HigherOrderCollectionProxy guard — both structurally match a relation
 * literally named `map`, so only their declaration order in analyzeValueExpression() keeps this
 * correct. A reorder would silently regress this with a fully green suite otherwise.
 *
 * @see Workbench\App\Http\Resources\MapRelationFilterResource
 */
export interface MapRelationFilterResource
{
    id: number;
    map: Pick<User, 'id' | 'name'>;
}
