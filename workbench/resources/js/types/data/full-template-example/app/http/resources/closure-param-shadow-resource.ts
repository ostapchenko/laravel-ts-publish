import type { User } from '../../models';

/**
 * A closure parameter that shadows a top-level local resolves through its own scoped binding
 * (whenLoaded relation / relation-chain element model), and must not leak the outer local's value.
 *
 * `outer_member` is a known over-degradation: the write-count shadow guard in
 * collectWrittenVariableNames() still counts the closure param as a write to `$member`, so the
 * top-level `$member` local is never bound. Narrowing that guard is deferred (see task-11-brief.md).
 *
 * @see Workbench\App\Http\Resources\ClosureParamShadowResource
 */
export interface ClosureParamShadowResource
{
    outer_member: unknown;
    mapped_members: User[];
    loaded_owner?: User;
    loaded_members_bare?: User[];
}
