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

Not yet wired up — `ImportNameRegistry` has no callers as of this writing. It is a
standalone, fully unit-tested support class; a follow-up migrates the transformers that
currently derive aliases with `ResolvesImportConflicts::computeNamespacePrefix()` onto it.
