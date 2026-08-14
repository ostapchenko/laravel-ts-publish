# ResourceAstAnalyzer

> User-facing docs: [README § API Resources](../../README.md#api-resources). Verified by
> [the type-inference gates](../testing/type-inference-gates.md).

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
for nullsafe calls — whenever **every filter key is a column the model interface actually
declares**, per `ModelAttributeResolver::publishedColumnNames()`.

That gate has to match the *emitted* interface, not just the schema. The raw schema listing
(`databaseColumnNames()`) is a superset only when `ts-publish.models.exclude_hidden` is enabled:
`ModelTransformer::transformColumns()` then skips `$hidden` columns, matching Laravel's own
serialization. `Pick<T, K>` constrains `K extends keyof T`, so naming a hidden column there would
be a hard `TS2344` — `publishedColumnNames()` subtracts them in that case, and such a key falls
back to inline expansion instead (which is also the more faithful output when the column is
excluded: `Model::only()` resolves through `getAttribute()` and *does* return hidden attributes at
runtime). The setting defaults to `false`, so by default hidden columns are published and
`publishedColumnNames()` includes them like any other column — see
[ModelAttributeResolver § `publishedColumnNames()` and the `exclude_hidden`
coupling](model-attribute-resolver.md#publishedcolumnnames-and-the-exclude_hidden-coupling).
`Omit<T, K>` does not constrain `K`, so it would compile either way; the same gate is
applied to both for one rule rather than two. A key that is an accessor, mutator, or relation
name falls back to the inline expansion unchanged —
`only()` can legitimately request those (Eloquent's `Model::only()` resolves through
`getAttribute()`, which reaches accessors and relations too), but the analyzer only optimizes
the plain-column case and leaves everything else to the existing inline path.

Both wrappers name the model's **own** interface (`Omit<Order, ...>`, `Pick<Order, ...>`) —
never `{Model}All`, never a mutators/relations interface — and they do so unconditionally, with
the same text emitted regardless of which model template is configured. What that name *means*
is template-dependent, so the correctness argument below is scoped to
`ts-publish.models.template`:

- **Under `model-split`** (the package default) `{Model}` is columns only; mutators, relations,
  counts, and exists live in `{Model}Mutators`/`{Model}Relations`, which only `{Model}All`
  unions back together. `Omit<Model, keys>` is therefore a columns-minus-keys type, which is
  exactly the argument that follows.
- **Under `model-full`** there is one interface per model carrying all of it. In
  `workbench/resources/js/types/data/full-template-example/app/models/order.ts`, `Order` holds
  the columns *and* `item_count`, `formatted_total`, `user`, `items`, `items_count`,
  `user_exists`, … So the `Omit<Order, 'created_at' | 'updated_at'>` in
  `.../full-template-example/app/http/resources/order-item-resource.ts` — byte-identical to the
  one in the `split-template-example` tree — spans all of those too, and describes a value with
  mutators and relations on it that `Model::except()` does not return at runtime. That is the
  same inaccuracy the old inline expansion had (see the end of this section); under `model-full`
  the Omit reference inherits it rather than fixing it. It still compiles — `Omit<T, K>` does not
  constrain `K` — and it is still a superset of the truth rather than a wrong-typed property, but
  it is not the tight match the split template gives.

`Pick<>` is unaffected by the template: its keys are gated on `publishedColumnNames()`, so they
are columns either way and the picked type is the same under both.

The `model-split` argument itself initially looked unsafe for `Omit<>` specifically — the bare
`{Model}`'s `keyof` doesn't span mutators or relations, which would make `Omit<Model, keys>`
narrower than the *old* inline expansion for a model with any mutator or relation. That
comparison is the wrong baseline, though:
`Illuminate\Database\Eloquent\Concerns\HasAttributes::except()` iterates
only `$this->getAttributes()` (the raw attribute array) — it never reads `$this->relations` at
all, and `mergeAttributeFromAttributeCasts()` explicitly refuses to merge a get-only `Attribute`
cast's value back into `$attributes` (`if ($attribute->get && ! $attribute->set) { return; }`),
so a get-only accessor can never surface even once it has been accessed. **At runtime,
`Model::except()` only ever returns database columns** — verified empirically in
`tests/Feature/ModelOnlyExceptSemanticsTest.php` against a real, DB-fetched `Post` instance with
a loaded relation and both get-only accessors (`excerpt`, `readingTime`) touched beforehand; the
result was identical to an untouched instance, and neither the relation nor either accessor
appeared. Under `model-split`, the bare-model `Omit<Model, keys>` this analyzer emits matches that
ground truth exactly. The *old* inline expansion's `except()` branch — which unions `$attrNames` (columns +
mutators) with `$relationNames` and subtracts the excluded keys — is the one that was
inaccurate: it shows relations and mutators `Model::except()` never actually returns at
runtime. That mismatch predates this feature and is unrelated to `only()`/`except()` no longer
being re-derived inline; it isn't fixed here (out of scope), but is worth knowing if you're
relying on the shape of an `except()`-filtered relation for a key that isn't a column.

## Import dispatch rules

A static-call value (e.g. `UrlService::locateOrder($id)`) whose method has no dedicated handler
falls through to `analyzeStaticCall()`'s general-reflection branch: it reflects the method's
return type into a `TypeScriptTypeInfo` and hands it to `acceptReflectedTypeInfo()`, which
decides whether the result can be emitted at all. The invariant is absolute: **a type token
never outruns its import** — nothing may be accepted unless every referenced name has a
resolvable `import` statement somewhere in the dispatch chain.

| Reflected shape | Accepted? | Dispatch channel |
| --- | --- | --- |
| Primitive / union of primitives (`string`, `int \| null`) | yes | emitted verbatim, no import needed |
| Single enum | yes | `directEnumFqcn` |
| Multiple enums (`Status\|Priority`) | yes | `embeddedEnumFqcns` (per-property inline-enum import plumbing) |
| Single `Model` subclass | yes | `modelFqcn` |
| Multiple / mixed `Model` subclasses | yes | `embeddedModelFqcns` |
| `Model` + enum union (`Order\|Status`) | yes | `modelFqcn`/`embeddedModelFqcns` *and* `directEnumFqcn`/`embeddedEnumFqcns` together — both channels fire off the same result |
| `#[TsType(import: ...)]`-annotated class | yes | `customImports`, merged into `ResourceAnalysis::$customImports` and consumed by `ResourceTransformer::mergeCustomImports()` |
| Any non-`Model` class (DTO, value object, cast class without `#[TsType]`) | **no** — degrades to `unknown` | none; this package generates no published file for an arbitrary class, so there is nothing to import |
| `void` / `never` / `mixed`'s reflected `unknown \| null` / empty type | **no** — degrades to `unknown` | none; these carry no meaningful TypeScript shape |

The non-`Model`-class rejection is a single guard checked before any channel is populated:
`Order|Status` and `Order|SomeDto` are structurally similar (`classFqcns` and `enumFqcns` both
non-empty) but only the first is importable, so every `classFqcns` entry must pass the
`is_a($fqcn, Model::class, true)` check or the whole result is rejected — accepting the enum
half while dropping an unimportable class token would still leak a compile error.

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

## Variable bindings

`$varModelBindings` (`array<string, class-string<Model>>`) maps a local variable name to a model
class, so `$var`, `$var->prop`, and `$var->method()` resolve against that model instead of
degrading to `unknown`. It is populated from three sources, each scoped to the body it binds:

- **`whenLoaded('relation', fn ($x) => ...)`** — when `relation` resolves to a *single*-model
  relation, `$x` is bound to that model for the closure body. A to-many relation's closure param
  is deliberately **not** bound this way: the param holds the whole collection, not one element,
  so binding it to the element model would resolve a bare `$x` to a wrong-but-plausible singular
  type (e.g. `OrderItem` instead of `OrderItem[]`) — `$x->pluck(...)`/`$x->map(...)` already
  resolve via the older `$closureRelationModelClass` mechanism, unaffected by this guard.
- **A relation-chain `map()`** (`$this->{manyRelation}->take(5)->map(fn ($m) => ...)`) — `$m` is
  bound to the relation's element model for the map closure's body.
- **A top-level `foreach ($this->{manyRelation} as $item) { ... }`** — `$item` is bound to the
  relation's element model for the rest of the method's analysis (mirrors `$localVarBindings`'
  method-wide scope, restored around a `...$this->method()` spread the same way).

### Scoping and shadowing

Every writer follows the same save/restore discipline as `$closureRelationModelClass`: snapshot
the map (or the one key being overwritten), mutate it for the nested body's analysis, then restore
the snapshot — so a closure parameter that shadows an outer variable of the same name resolves
against its **own** binding and can never leak into, or be leaked into by, the outer scope. See
`ClosureParamShadowResource` in the workbench: a top-level `$member` and a `map(fn ($member) =>
$member)` closure param share a name, and each site resolves independently.

`outer_member` in that same fixture is a known, accepted gap: `collectWrittenVariableNames()`
still counts every closure parameter as a write (needed to protect `$localVarBindings`, an
unrelated, unscoped mechanism, from a *different* shadowing hazard), so a top-level local shadowed
by a closure param stays unbound rather than resolving to its own top-level expression. Fixing
that is out of scope here — narrowing which closures count as writes would require statically
predicting whether a given closure will actually receive a `$varModelBindings` entry, which risks
the exact wrong-but-plausible leak this mechanism exists to prevent.

### What deliberately stays unbound

- **A reassigned local** (written more than once in the method) — `$localVarBindings` already
  skips these; `$varModelBindings` has no reassignment analog since it only ever binds closure
  params and loop variables, each written exactly once by construction.
- **First-class callables** (`->map(...)`, `->pluck(...)`) — there is no closure body to bind a
  param into, so these are rejected before any binding is attempted.
- **A relation-chain `map()` whose argument isn't a `Closure`/`ArrowFunction`** (a string callable
  like `'strtoupper'`, or an array callable like `[$this, 'method']`) — same reasoning.

## Method-spread recursion guard

`analyzeThisMethodSpread()` resolves a `...$this->method()` spread by re-entering the analyzer on
that method's own `toArray()`-style return. `$visitedSpreadMethods` (`array<string, true>`) tracks
which method names are currently on that re-entry stack; a method already on the stack returns
`null` instead of being analyzed again, so a cycle — direct (`method()` spreads itself) or mutual
(`alpha()` spreads `beta()`, `beta()` spreads `alpha()`) — degrades to an empty analysis rather than
recursing. The entry is set right after the `hasMethod()` check and cleared in the same `finally`
that already restores `$localVarBindings`/`$resolvingLocalVars`/`$varModelBindings`, so it clears on
the exception path too.

Before this guard existed, a mutually recursive spread had no way to terminate: each call re-entered
`analyzeThisMethodSpread()` for the other method, which re-entered the AST parser, until PHP's memory
limit was exhausted (a 512 MB limit fails after roughly 22,000 stack frames). This was reachable
through the existing spread path with no special setup — see `MutuallyRecursiveSpreadResource` in
the workbench and the corresponding test in `ResourceAstAnalyzerTest`. The guard is load-bearing, not
defensive: it fixes a crash that was previously trivial to trigger, not a theoretical one.

## `whenNotNull()`/`whenNull()` read `($value, $default)`, not a callback

`analyzeWhenPossiblyNull(MethodCall $call, bool $stripNull)` handles both `$this->whenNotNull($value,
$default)` and `$this->whenNull($value, $default)`. Both delegate to `ConditionallyLoadsAttributes::when()`
(`vendor/laravel/framework/.../Http/Resources/ConditionallyLoadsAttributes.php`): `whenNotNull($value,
$default)` is `$this->when(! is_null($value), $value, $default)`, and `whenNull($value, $default)` is
`$this->when(is_null($value), $value, $default)`. Argument 0 is always the value returned on the
condition's success arm; argument 1, when passed, is the default returned on the failure arm. Neither
argument is ever a callback bound to the other — `value()` invokes a Closure passed as `$value`/`$default`
with **zero** arguments, never with the sibling argument as a parameter.

An earlier version of this handler's docblocks claimed a callback form ("whenNotNull($this->value,
$callback) — resolve the callback expression type") that Laravel does not have, and the implementation
matched that wrong docblock: it read `$args[1]` unconditionally and returned that as the property's type.
So `whenNotNull($this->description, 0)` (`description: string | null`) emitted `?number` — the default's
type alone — instead of `string | number, required`. The fix was checked against
`ConditionallyLoadsAttributes.php` directly rather than assumed; see that trait for the exact
`func_num_args()` gating this handler mirrors.

### Which arm each analysis path reads

- **`whenNotNull()`** (`stripNull: true`) analyzes argument 0, then strips a trailing `| null` from its
  type via `stripNullArm()`: the `! is_null($value)` guard on the success arm proves that arm unreachable,
  so `whenNotNull($this->description)` emits `?string`, not `?string | null`.
- **`whenNull()`** (`stripNull: false`) forces argument 0's contribution to the literal string `'null'`
  instead of analyzing it — the success arm always returns `null` when the guard holds, so the value's own
  type is irrelevant to what the property can be.

### The default argument controls both `optional` and the union

`hasExplicitDefaultArg(MethodCall $call, int $index)` decides whether argument 1 was passed at all — purely
positionally, since Laravel distinguishes an omitted argument from an explicitly-passed `null` via
`func_num_args()`, not via `$value === null`. A `ConstFetch(null)` at the default position
(`whenNotNull($x, null)`) counts as an explicit default: `func_num_args() === 2` there too, so the key
survives at runtime as `null`, not as a missing key. Named or spread arguments make position meaningless,
so both bail the helper out to `false` rather than guessing — it is reused as-is by later conditional-family
handlers for the same reason.

When no explicit default is present, the property is `optional: true` and its type is just the (possibly
null-stripped) value arm — matching every pre-existing single-argument fixture (`ProductResource`,
`ImageResource`, `AddressResource`, …). When an explicit default *is* present, the key can never be a
`MissingValue`, so `optional` becomes `false`, and the default's own type — analyzed independently via
`analyzeValueExpression()`, since PHP evaluates it eagerly as an argument regardless of which arm ultimately
wins at runtime — is unioned into the value's via `mergeUnionChannels()`. Both arms are split on their own
`' | '` members and deduplicated before merging (mirroring `analyzeClosureUnion()`'s approach), so a
same-typed default collapses to one type instead of a redundant `number | number`, and an `unknown` arm (an
unresolvable closure body passed as the default) is dropped rather than unioned in literally — mirroring how
`analyzeCoalesce()` treats an `unknown` operand.

### A model-level `#[TsCasts]` override can mask this fix in the final output

`AddressExtendsResource`/`AddressMixinResource` both call `whenNotNull($this->latitude)` — the value-arm fix
alone makes that `latitude?: number` at the analyzer level. It doesn't reach the generated `.ts`: `Address`
carries a model-level `#[TsCasts(['latitude' => ...])]` override, and
`ResourceTransformer::applyOverrides()` applies model-level `#[TsCasts]` *after* AST analysis, so it wins
regardless of what this handler determines. That is expected, unrelated behavior, not a regression — verify
the value-arm/default-arm split against a fixture whose properties carry no `#[TsCasts]` override (e.g.
`ConditionalDefaultsResource`, or `AddressResource`'s `line_2`) rather than against `latitude`/`longitude`.
