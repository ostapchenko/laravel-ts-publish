import type { User } from '../../models';

/**
 * Exercises a class-typed `@property` tag reaching a resource, where the token still needs its import.
 *
 * @see Workbench\App\Http\Resources\PropertyDocblockEdgeResource
 */
export interface PropertyDocblockEdgeResource
{
    id: number;
    owner_snapshot: User | null;
    meta_info: unknown[] | null;
    tags: unknown[] | null;
}
