/**
 * Exercises userland global-helper reflection (route()), Carbon
 * receiver-method inference on a datetime-cast attribute, and the
 * can()/count() known-method rules (Task 11).
 *
 * `diff_result` and `period_result` are a Task 12 regression: Carbon methods that
 * return a Stringable-but-not-string value object (CarbonInterval, CarbonPeriod) must
 * degrade to unknown rather than falsely resolve to `string` via toTsType()'s
 * __toString fallback.
 *
 * @see Workbench\App\Http\Resources\HelperCallResource
 */
export interface HelperCallResource
{
    route_url: string;
    ship_date: string;
    can_edit: boolean;
    item_total: number;
    diff_result: unknown;
    period_result: unknown;
}
