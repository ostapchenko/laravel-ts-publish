/**
 * The naming-convention candidate for Workbench\App\Models\TrackingEvent — deliberately present so
 * the #[UseResource(EventLogResource::class)] precedence test is non-vacuous: this class must lose.
 *
 * @see Workbench\App\Http\Resources\TrackingEventResource
 */
export interface TrackingEventResource
{
    id: number;
}
