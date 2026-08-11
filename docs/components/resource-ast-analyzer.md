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
for nullsafe calls — whenever **every filter key is a real database column** of the related
model, per `ModelAttributeResolver::databaseColumnNames()` (the live schema listing — the same
source `ModelTransformer::transformColumns()` uses to decide `$dbColumns`, so a `Pick`/`Omit`
reference can never name a key the model interface doesn't also derive from that schema). A key
that is an accessor, mutator, or relation name falls back to the inline expansion unchanged —
`only()` can legitimately request those (Eloquent's `Model::only()` resolves through
`getAttribute()`, which reaches accessors and relations too), but the analyzer only optimizes
the plain-column case and leaves everything else to the existing inline path.

Both wrappers target the **bare** model interface (columns only), unconditionally — this
initially looked unsafe for `Omit<>` specifically (this package's default `model-split` model
template puts mutators/relations in separate `{Model}Mutators`/`{Model}Relations` interfaces
the bare `{Model}`'s `keyof` doesn't span), which would make `Omit<Model, keys>` narrower than
the *old* inline expansion for a model with any mutator or relation. That comparison is the
wrong baseline, though: `Illuminate\Database\Eloquent\Concerns\HasAttributes::except()` iterates
only `$this->getAttributes()` (the raw attribute array) — it never reads `$this->relations` at
all, and `mergeAttributeFromAttributeCasts()` explicitly refuses to merge a get-only `Attribute`
cast's value back into `$attributes` (`if ($attribute->get && ! $attribute->set) { return; }`),
so a get-only accessor can never surface even once it has been accessed. **At runtime,
`Model::except()` only ever returns database columns** — verified empirically in
`tests/Feature/ModelOnlyExceptSemanticsTest.php` against a real, DB-fetched `Post` instance with
a loaded relation and both get-only accessors (`excerpt`, `readingTime`) touched beforehand; the
result was identical to an untouched instance, and neither the relation nor either accessor
appeared. The bare-model `Omit<Model, keys>` this analyzer emits matches that ground truth
exactly. The *old* inline expansion's `except()` branch — which unions `$attrNames` (columns +
mutators) with `$relationNames` and subtracts the excluded keys — is the one that was
inaccurate: it shows relations and mutators `Model::except()` never actually returns at
runtime. That mismatch predates this feature and is unrelated to `only()`/`except()` no longer
being re-derived inline; it isn't fixed here (out of scope), but is worth knowing if you're
relying on the shape of an `except()`-filtered relation for a key that isn't a column.

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
