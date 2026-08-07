/**
 * Exercises userland global-helper reflection (route()), Carbon
 * receiver-method inference on a datetime-cast attribute, and the
 * can()/count() known-method rules (Task 11).
 *
 * @see Workbench\App\Http\Resources\HelperCallResource
 */
export interface HelperCallResource
{
    route_url: string;
    ship_date: string;
    can_edit: boolean;
    item_total: number;
}
