# ImportNameRegistry

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
- **Const-alias mirroring.** For an enum FQCN whose resolved local name differs from its type
  name, the paired const import (`$constImportAliases`) reuses the same prefix: the resolved
  alias minus the type name, prepended to the enum's const name. `StatusType` aliased to
  `CrmStatusType` mirrors `Status` to `CrmStatus`.

Still remaining: `BroadcastEventTransformer` and `ResourceTransformer` derive aliases with
`ResolvesImportConflicts::computeNamespacePrefix()` directly and have not been migrated onto
the registry.
