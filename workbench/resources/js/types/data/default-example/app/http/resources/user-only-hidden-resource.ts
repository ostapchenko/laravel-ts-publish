/**
 * Exercises return $this->only([...]) naming a $hidden column explicitly.
 *
 * The property set is exactly the named keys, so it is explicit — exclude_hidden must not
 * drop `password` here, since the caller named it.
 *
 * @see Workbench\App\Http\Resources\UserOnlyHiddenResource
 */
export interface UserOnlyHiddenResource
{
    id: number;
    password: string;
}
