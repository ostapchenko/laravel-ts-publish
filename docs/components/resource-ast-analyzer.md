# ResourceAstAnalyzer

`AbeTwoThree\LaravelTsPublish\Analyzers\ResourceAstAnalyzer` walks a `JsonResource`
subclass's `toArray()` method as PHP AST (not reflection) and infers a TypeScript type for
every returned property, following relation chains, closures, casts, and accessor waterfalls
back to their source.

## Relation filters

`$this->relation->only([...])` / `->except([...])` (and their `?->` nullsafe forms) analyze
via `analyzeRelationFilter()`. When the related model resolves to a single class (not a
multi-model accessor union such as `Attribute<ModelA|ModelB, never>`), the analyzer prefers to
**reference the related model's own generated interface** instead of re-deriving an inline
object shape — the model interface already carries `#[TsCasts]` overrides and `@property`
docblock refinements that a from-scratch recompute loses.

### When a Pick/Omit reference is emitted

`relationFilterModelReference()` builds `Pick<Model, 'a' | 'b'>` (for `only()`) or
`Omit<Model, 'a' | 'b'>` (for `except()`) — `[]`-suffixed for many-relations, `| null`-suffixed
for nullsafe calls — under these conditions:

- **Every filter key must be a real database column** of the related model, per
  `ModelAttributeResolver::databaseColumnNames()` (the live schema listing — the same source
  `ModelTransformer::transformColumns()` uses to decide `$dbColumns`, so a `Pick`/`Omit`
  reference can never name a key the model interface doesn't also derive from that schema). A
  key that is an accessor, mutator, or relation name falls back to the inline expansion
  unchanged — `only()` can legitimately request those (Eloquent's `Model::only()` resolves
  through `getAttribute()`, which reaches accessors and relations too), but the analyzer only
  optimizes the plain-column case and leaves everything else to the existing inline path.

- **`Omit<>` additionally requires the bare model interface to already be complete** — its
  `keyof` must span every column, mutator, *and* relation, not just columns. This is checked
  via `ModelAttributeResolver::baseModelInterfaceIsComplete()`. The reason: this package ships
  two model templates. `model-full` merges columns, mutators, and relations into one
  interface, so the bare model name always qualifies. The default `model-split` template
  instead puts mutators in `{Model}Mutators` and relations in `{Model}Relations`, combined
  only in a separate `{Model}All` interface — and there is currently no plumbing to import
  that combined interface under a name distinct from the bare model FQCN's `Pick`/`Omit`
  target. Referencing the bare `{Model}` for `Omit<>` in that case would silently drop every
  mutator and relation the old inline expansion used to include, which is worse than the
  `unknown` types this feature exists to fix. So under `model-split` (or any unrecognized
  custom template), `Omit<>` only fires when the model has *no* mutators or relations at all —
  otherwise the analyzer falls back to the existing inline expansion, unchanged.

  `Pick<>` has no such restriction: it only ever needs the picked keys, and a plain column is
  always present on the bare model interface regardless of template.

### Multi-model accessor unions are untouched

The other branch of `analyzeRelationFilter()` — an accessor typed as a union of two or more
Eloquent models, e.g. `Attribute<CrmUser|User, never>` — is left on the pre-existing inline
expansion entirely. Each union member's `Pick`/`Omit` reference would need its own
alias-correct import (two models can share a basename across namespaces, as `CrmUser`/`User`
do in the workbench fixtures), and the FQCN-dispatch channel `analyzeRelationFilter()` already
has (`embeddedModelFqcns`, self-keyed by FQCN) does not carry the per-property association
needed to alias-rewrite each member's reference correctly. Extending Pick/Omit to this branch
is possible but requires new per-property import plumbing — tracked as follow-up work, not
part of this feature.
