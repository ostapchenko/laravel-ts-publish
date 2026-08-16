/**
 * toArray() returns a method call directly rather than spreading it into an array literal, and that
 * method in turn returns another — the transitive case.
 *
 * @see Workbench\App\Http\Resources\BareMethodReturnResource
 */
export interface BareMethodReturnResource
{
    id: number;
    slug: string;
}
