import { type AsEnum } from '@tolki/ts';

import { Status } from '../../enums';

/**
 * Regression fixture for Task 17A: SomeClass::CONSTANT as a property value resolves to a real TS
 * type via reflection instead of unknown. Also pins that `Foo::class` and an enum case reached
 * through EnumResource::make() keep resolving exactly as before alongside the new feature.
 *
 * @see Workbench\App\Http\Resources\ClassConstantResource
 */
export interface ClassConstantResource
{
    owner_minimum_channels: { in_app: { status_updates: boolean; comments: boolean }; digest: { status_updates: boolean; comments: boolean } };
    max_retries: number;
    schema_version: number;
    fallback_channels: { in_app: { status_updates: boolean; comments: boolean }; digest: { status_updates: boolean; comments: boolean } };
    channel_tags: unknown;
    resource_marker: string;
    status_marker: AsEnum<typeof Status>;
}
