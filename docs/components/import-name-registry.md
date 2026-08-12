# ImportNameRegistry

> User-facing docs: [README § Models](../../README.md#models). Verified by [the type-inference gates](../testing/type-inference-gates.md).

`AbeTwoThree\LaravelTsPublish\Support\ImportNameRegistry` assigns collision-free local
TypeScript names to the FQCNs a generated `.ts` file imports. A transformer registers every
FQCN it needs to import — plus any names the file already declares for itself, via
`reserve()` — and `resolve()` returns a deterministic `FQCN => local name` map. It exists
because the previous aliasing approach (a single namespace-segment prefix) could assign the
*same* alias to two *different* classes: two unrelated `MailPrice` models both under an
`Engineering` segment resolved to the identical `EngineeringMailPrice`, a TypeScript
duplicate-identifier error. `ImportNameRegistry` guarantees that cannot happen.

## Algorithm

Every FQCN registered under a type name starts with a candidate local name — a ladder it
climbs only as far as needed:

1. **Preferred alias**, if the caller supplied one (e.g. a relation-derived name such as
   `OwnerUser`) — used only if it is globally unique.
2. **Namespace segments, nearest-first.** Failing (or absent) a preferred alias, the
   candidate is the immediate parent namespace segment (StudlyCase) prefixed to the type
   name — `MailPrice` → `EngineeringMailPrice`. Segments in `$skipSegments` (default
   `Models`, `Enums`, `App`) are filtered out first, and a configured
   `ts-publish.namespace_strip_prefix` is stripped from the namespace before segments are
   read. If every segment is skip-listed (e.g. `App\Models\Order`), the unfiltered segments
   are used instead so the alias can still be built.
3. **Extend one segment deeper per round.** Every member of a colliding group — same type
   name registered more than once, or a type name that is reserved — advances *together*,
   each round: a colliding preferred alias drops to the one-segment prefix; a colliding
   prefix extends one segment further into the namespace (`MailPrice` →
   `EngineeringMailPrice` → `CustomerEngineeringMailPrice`). Advancing the whole group each
   round, rather than only the newest collider, means no member is ever left holding a
   shallow alias that merely happens to look unique against the *other* member's *previous*
   candidate.
4. **Numeric suffix**, as the final tiebreak, for any member that exhausts its namespace
   (or two FQCNs that are otherwise identical past the root) — `2`, `3`, … in FQCN-sorted
   order, the first member keeping the unsuffixed name.

## Invariants

- **Global uniqueness.** `resolve()`'s values never collide with each other, and never equal
  a name passed to `reserve()`. A reserved name forces even a lone import of that name to
  alias (e.g. `App\Models\Order` becomes `ModelsOrder` when `Order` is reserved by the file
  itself).
- **Determinism.** The alias a given FQCN receives depends only on the *set* of FQCNs and
  type names registered — never on the order `register()` was called in. Within a colliding
  group this comes from sorting members by FQCN before assigning numeric tiebreaks; across
  groups with different type names, from processing groups in type-name order rather than
  registration order.

## Consumers

Each transformer's `resolveImportConflicts()` builds the registry (or registries) that fit its
own FQCN maps, then hands the resolved names to the shared
`ResolvesImportConflicts::applyResolvedImportNames()` step — the part that actually assigns
aliases and, when anything was aliased, triggers `rewriteTypeReferences()` — rather than each
transformer walking `$registry->resolve()` itself.

### `ModelTransformer`

`ModelTransformer::resolveImportConflicts()` builds one `ImportNameRegistry` per model file:

- **Reserved names.** The model's own interface name (`$this->modelName`) is reserved before
  anything is registered, so an imported class that happens to share the current model's name
  is forced to alias even if it would otherwise be the only member of its group.
- **Registration.** Every enum FQCN in `$enumFqcnMap` is registered with no preferred alias.
  Every model FQCN in `$modelFqcnMap` (excluding the model being transformed) is registered
  too; if it backs exactly one relation, the relation name supplies a preferred alias
  (`Str::studly($relation).$typeName`, e.g. `OwnerUser`) — a model reached by two or more
  relations, or not reached by any (e.g. a plain column cast), gets no preferred alias and
  starts straight from namespace segments.
- **Const-alias mirroring.** Const names are resolved through a second, sibling
  `ImportNameRegistry` (same skip list) rather than derived by string-slicing the type
  alias — slicing breaks once a member reaches the numeric tiebreak, where the local name is
  no longer literally "prefix + type name". Because a const name collides exactly when its
  paired type name does (both are deterministic functions of the same enum FQCN), the sibling
  registry naturally mirrors the type registry's chosen depth: `StatusType` aliased to
  `CrmStatusType` pairs with `Status` aliased to `CrmStatus`.
- **Applying the result.** `applyResolvedImportNames($registry->resolve(), $this->enumFqcnMap +
  $this->modelFqcnMap, $constRegistry->resolve())` — the `+` union is safe because a given FQCN
  is never legitimately both an enum and a model.

### `ResourceTransformer`

`ResourceTransformer::resolveImportConflicts()` follows the same shape as `ModelTransformer`,
with `new ImportNameRegistry(['Models', 'Enums', 'Http', 'Resources', 'App'])`:

- **Reserved names.** The resource's own interface name (`$this->resourceName`) is reserved.
- **Registration.** Every enum FQCN in `$enumFqcnMap`, resource FQCN in `$resourceFqcnMap`,
  and model FQCN in `$modelFqcnMap` is registered with no preferred alias — resources have no
  relation-derived preference the way `ModelTransformer`'s models do.
- **Const-alias mirroring.** Identical to `ModelTransformer`: a sibling registry (same skip
  list) resolves enum const aliases independently of the type aliases.
- **Applying the result.** `applyResolvedImportNames($registry->resolve(), $this->enumFqcnMap +
  $this->resourceFqcnMap + $this->modelFqcnMap, $constRegistry->resolve())` — the three-way
  union is safe because no FQCN is written into more than one of the three maps. `resourceFqcnMap`
  is populated only from `analysis->nestedResources` (`JsonResource` subclasses), and
  `modelFqcnMap` only from `analysis->modelFqcns` and the multi-class accessor branch in
  `resolveMultiClassAccessorFqcns()` — both restricted, by how the upstream analyzers build
  their `classFqcns`, to Eloquent model classes. This is a property of *where these maps are
  populated from*, not something PHP's type system enforces (a class extending both `Model` and
  `JsonResource` is technically possible; nothing in this codebase creates or expects one).

### `BroadcastEventTransformer`

`BroadcastEventTransformer::resolveImportConflicts()` uses one `ImportNameRegistry` per event
file, `new ImportNameRegistry(['Events', 'Enums', 'Models'])`:

- **Reserved names.** The event's own interface name (`$this->eventName`) is reserved.
- **Registration.** Every enum FQCN in `$enumFqcnMap` and model FQCN in `$modelFqcnMap` is
  registered with no preferred alias.
- **No const-alias handling.** `$enumConstMap` is always empty on this transformer (broadcast
  event payloads reference enums as types, never as tolki `AsEnum<typeof Const>` values), so
  there is no sibling const registry — mirroring one here would be dead code.
- **Applying the result.** `applyResolvedImportNames($registry->resolve(), $this->enumFqcnMap +
  $this->modelFqcnMap)` — the third argument is omitted, defaulting to `[]`, so const aliases
  are never populated.

All three transformer consumers are now on `ImportNameRegistry`; no transformer derives
aliases from a single namespace segment anymore.
