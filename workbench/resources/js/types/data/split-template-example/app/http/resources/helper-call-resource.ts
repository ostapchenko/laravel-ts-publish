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
 * `to_mutable`/`to_immutable` are a Task 12 review follow-up: unlike CarbonInterval/
 * CarbonPeriod, Carbon and CarbonImmutable themselves ARE correctly `string` via
 * __toString() (their canonical ISO-ish datetime representation), so the Stringable
 * guard must not over-degrade these two.
 *
 * `user_key` is a Task 12 review follow-up (Important 4): `getKey()`'s type depends
 * on which model it's called on, unlike can()/cannot()/canAny() which are bool
 * regardless of receiver — so getKey() must NOT fire on an arbitrary receiver like
 * `$request->user()`, only on `$this->resource`. Must stay unknown.
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
    to_mutable: string;
    to_immutable: string;
    user_key: unknown;
}
