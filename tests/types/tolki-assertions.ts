import type { AsEnum, RouteCallResult } from '@tolki/ts';

// A broken dts import degrades the whole surface to `any`, and `any` silences every downstream
// diagnostic - an `IsAny` conditional yields the error type, not `true`, so it can never fail.
// Only TS2578 (unused directive) survives, so each guard asserts a violation only a real type raises.

// @ts-expect-error TS2344 - `string` is not an EnumConst; silence means enums.d.ts degraded to `any`.
export type AsEnumRejectsNonEnumConst = AsEnum<string>;

// @ts-expect-error TS2344 - `number` is not a string; silence means routes.d.ts degraded to `any`.
export type RouteCallResultRejectsNonString = RouteCallResult<number>;

declare const Probe: {
    readonly Monday: 'monday';
    readonly Tuesday: 'tuesday';
    readonly backed: true;
    readonly _cases: readonly ['Monday', 'Tuesday'];
};

// Positive checks: these resolve to real unions only once the imports are repaired.
export const probeName: AsEnum<typeof Probe>['name'] = 'Monday';
export const probeValue: AsEnum<typeof Probe>['value'] = 'monday';
