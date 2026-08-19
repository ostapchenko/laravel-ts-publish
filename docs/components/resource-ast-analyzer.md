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

**Mutator half fixed in a later pass:** `buildModelDelegatedAnalysis()`'s own property set — used
by whole-model delegation and `return $this->except([...])`, not the relation-filter inline path
above — no longer emits a write-only mutator with no getter and no docblock `Get` generic (e.g.
`search_index: unknown`). `ModelAttributeResolver::isOmittedMutator()` is the same signal
`ModelTransformer::transformMutators()` uses to drop it there, so the two now agree for that
model. A real database column is never dropped by this check even when its own resolved type is
`unknown`, matching `transformColumns()`, which always keeps a column.

The skip is gated on `$excludeHidden` — true for the implicit paths, false only for `only()` — for
the same reason `HasAttributes::except()`/`only()` themselves diverge: `except()` (`:2146`) iterates
`getAttributes()`, which a write-only mutator was never added to, so it truly cannot appear;
`only()` (`:2129`) instead calls `getAttribute($key)` per named key, which *does* return the key
(as `null`, absent a getter to transform a stored value) even for a write-only mutator. So
`return $this->only(['search_index'])` keeps `search_index: unknown` — `unknown` because nothing
here infers a type from a *setter*, and that already admits the `null` `getAttribute()` would
return. `OrderOnlyResource`'s fixture pins this against `search_index` directly.

The relations half of the inaccuracy above is untouched — `Model::except()` never returns a
relation either, and that stays out of scope. `resolveFilteredRelationType()`'s except branch
(`$this->relation->except([...])`) now applies the same `isOmittedMutator()` rule as
`buildModelDelegatedAnalysis()`: only a write-only mutator with no getter and no docblock `Get`
generic drops out, so a mutator that *has* a getter but resolves to an untypeable `unknown`
survives as `key: unknown`, matching the whole-model path. The two `except()` paths now agree.
A side effect on the sibling include branch (`$this->relation->only([...])`) follows from the
same shared gate: an explicitly-named getter-backed accessor that resolves to `unknown` now also
survives instead of vanishing from the inline shape — runtime-faithful, since `Model::only()`
resolves through `getAttribute()`, which does return that key.

### `exclude_hidden` on the top-level resource, not just relation filters

The rule established above for `$this->relation->only()/except()` — hidden columns fall out of an
*implicitly* derived property set but survive one the caller *named* — is not scoped to relation
filters. It governs every path that resolves the top-level resource's own property set from its
`@mixin` model, because it is the same distinction Eloquent itself draws: `Model::only()` resolves
through `getAttribute()` and returns a `$hidden` attribute regardless, while `toArray()`/`except()`
go through `getArrayableItems()`, which strips it.

**Implicit — `exclude_hidden` drops the hidden column:**

- **Whole-model delegation** — `return parent::toArray($request)`, `[...parent::toArray($request)]`
  as a spread inside an array literal, or a resource with no `toArray()` at all — all three reach
  `buildModelDelegatedAnalysis()` (the last two via `analyzeParentToArray()`) and build the property
  set from every model attribute.
- **`return $this->except([...])`** — `analyzeExceptFilter()` derives its base set from that same
  method, then subtracts the named keys; a hidden column that was never named to be *kept* falls
  out with the rest.
- **`$this->relation->except([...])`** — `resolveFilteredRelationType()`'s except branch builds its
  key list from every attribute and relation name on the related model.

**Explicit — `exclude_hidden` leaves it alone:**

- **`return $this->only([...])`** — the property set is exactly the caller's key list.
- **`$this->relation->only([...])`** — the include branch above; a named hidden column falls back
  to inline expansion instead of a `Pick<>` reference, but it is never dropped.
- **`$this->whenHas('column')`** — the attribute name is a literal argument to the call.
- **Plain `@mixin` property access** — `'password' => $this->password` — the single most common
  resource idiom. `analyzeThisProperty()` resolves it via `resolveModelAttributeTypeInfo()`, the
  same single-attribute path `whenHas()` uses; it never reaches `buildModelDelegatedAnalysis()`, so
  a hand-written key survives `exclude_hidden` exactly like a named `only()` key does.

Two touch points implement the implicit side. `buildModelDelegatedAnalysis()`
(`ResolvesModelTypes.php`) is the property-set builder shared by whole-model delegation *and*
`analyzeOnlyFilter()`/`analyzeExceptFilter()` — the include filter needs the unfiltered set to
select an explicitly-named hidden column from, so the method takes a `bool $excludeHidden = true`
parameter instead of filtering unconditionally: `analyzeOnlyFilter()` is the one caller that
passes `false`. `resolveFilteredRelationType()`'s except branch has no such sharing problem — it
builds its key list fresh per call — so it filters unconditionally there; `WarehouseResource`'s
`last_user_activity_by_mostly` (a multi-model accessor union reaching the `except()` branch through
`analyzeRelationFilter()`'s accessor-union loop) is the fixture that pins this touch point against
`User::$hidden`. Three sites are
deliberately left untouched because each already takes the caller's request verbatim rather than
deriving it from the full attribute list: `filterAnalysisByKeys()`, the include branch of
`resolveFilteredRelationType()`, and `ModelAttributeResolver::resolveAttribute()` (the
single-attribute resolution that `only()` and `whenHas()` both end up calling).

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

### `directEnumFqcn` carries two entry kinds

The `directEnumFqcn` channel and `ResourceTransformer::$directEnumProperties` map share one array
carrying two structurally different entry kinds: property-name keys pointing to direct-access enum
FQCNs (from ternary/union branches), and self-keyed FQCN entries (from embedded enums in inline
relation filters). All three consumers live inside `ResourceTransformer::rewriteEnumResourceTypes()`:
the `$isMixed` check is key-sensitive, testing whether a property name is a key in the map, while
the other two — both import-garbage-collection loops — compare values only and work correctly for
both entry kinds. `substituteEnumResourceType()` never reads this map at all.

### The tolki `AsEnum` wrap substitutes a token; it never rebuilds the type

An `EnumResource::make()`-wrapped property reaches the `enumResources` channel carrying the
analyzer's own type string, in which the enum appears as its bare TS type name (`RoleType` —
`TypeScriptTypeInfo::$enumTypes[0]`, i.e. the `#[TsEnum]` name or the class basename, suffixed
`Type`). With `enums.use_tolki_package` on, **both** rewrite paths turn that into
`AsEnum<typeof Role>` by *substituting the bare token in place*:
`ResourceTransformer::substituteEnumResourceType()` for a top-level property, and
`ResourceAstAnalyzer::substituteEnumType()` for one nested inside an inline array literal.
Both use the same word-boundary pattern — the lookbehind excludes `.` so a namespace-qualified
`foo.RoleType` is left alone, the lookahead stops `RoleType` matching the prefix of
`RoleTypeExtra`.

Substitution matters because the analyzer's type is often richer than `X`/`X[]`. A
key-clearing chain (`filter()->map(fn (…) => EnumResource::make(…))`) emits
`RoleType[] | Record<string, RoleType>`; a conditional with an explicit default emits an extra
arm such as `RoleType | string`. Rebuilding the type from the FQCN — which is what both paths
used to do — could express only `X`, `X[]`, `X | null`, `X[] | null`, so every richer shape had
to be demoted out of the `enumResources` channel by a guard (`isRebuildableEnumShape()`) and
left un-wrapped. That guard is gone: substitution reproduces any shape losslessly, wrapping
every arm that names the enum and leaving the rest of the union untouched.
`RelationChainResource::$member_role_resources_filtered` (top level) and `$wrapped_filtered`
(inline array) pin the two paths against the identical PHP shape — they must never disagree.

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

### Closure params vs. `$localVarBindings`

`collectWrittenVariableNames()` used to count every closure/arrow-function *parameter* as a write
to the enclosing name pool, so a top-level `$member = $this->slug;` reused as a `map(fn ($member)
=> $member)` param elsewhere in the same method looked written twice, and `collectLocalVarBindings()`
(which only binds names written exactly once) never bound `$member` — even at the top-level site the
closure never touches. `outer_member` in `ClosureParamShadowResource` demonstrated the gap. Now
`collectWrittenVariableNames()` only counts assignments, mutations, `foreach` targets, and a
`Closure`'s by-ref `use (&$x)` clause; closure/arrow params are no longer collected there.

That narrowing alone would have introduced a *worse* defect: a closure parameter shadowing an outer
local, inside a construct with no scoped binding for it (none of the three `$varModelBindings`
sources above, e.g. `when()`'s condition isn't a `$this->prop` test), would resolve through the
outer `$localVarBindings` entry when analyzing the closure body — turning an honest `unknown` into
a confidently wrong `string`. To prevent that, the generic closure/arrow-function descent in
`analyzeValueExpression()` saves `$localVarBindings`, unsets any entry whose name matches one of the
closure's own parameters, analyzes the body, and restores the snapshot in a `finally` — so a param
with no binding of its own degrades to `unknown` inside the closure, never the outer local's value.

`ShadowedClosureParamResource` in the workbench exists to hold that second half of the fix: its
`$slug = $this->slug;` followed by `$this->when($request->user() !== null, fn ($slug) => $slug)`
has a condition that isn't a `$this->prop` test, so `bindClosureParamsFromCondition()` binds nothing
for the closure's `$slug`. The closure-descent suppression above is the only thing keeping `shadowed`
at `unknown` rather than leaking the outer `$slug`'s `string` type — narrow the write count without
it, and `shadowed` silently becomes `string`; this fixture's test is what catches that regression.

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
the exception path too. The NodeFinder predicate that locates the target method compares names with
`strcasecmp()`, so call-site casing is normalized to match PHP's own case-insensitive method dispatch.

Before this guard existed, a mutually recursive spread had no way to terminate: each call re-entered
`analyzeThisMethodSpread()` for the other method, which re-entered the AST parser, until PHP's memory
limit was exhausted (a 512 MB limit fails after roughly 22,000 stack frames). This was reachable
through the existing spread path with no special setup — see `MutuallyRecursiveSpreadResource` in
the workbench and the corresponding test in `ResourceAstAnalyzerTest`. The guard is load-bearing, not
defensive: it fixes a crash that was previously trivial to trigger, not a theoretical one.

## A bare `return $this->method()` resolves transitively

`analyze()`'s fallback path used to route every non-array `MethodCall` return through
`analyzeThisAttributeFilter()`, which returns `null` for any method name outside `['only', 'except']`
— so `return $this->data();` produced an empty interface even though the array-literal spread form,
`return [...$this->data()];`, already worked. `analyze()` now falls back to
`analyzeThisMethodSpread($methodName)` when the attribute-filter path declines, resolving the bare
return the same way a `...$this->method()` spread already did.

`analyzeThisMethodSpread()`'s own return dispatch gained a matching `MethodCall` arm, so a method that
returns another method call (`data()` returning `$this->nested()`) recurses instead of degrading to an
empty analysis. `only()`/`except()` are still resolved first at each level via
`analyzeThisAttributeFilter()`, so a `return $this->only([...])` reached transitively keeps working
exactly as a direct one does.

Because `ReflectionMethod::getFileName()` resolves to wherever a method is *declared* — the resource
itself, a trait it uses, or a parent class — this recursion reaches trait- and parent-declared methods
for free; `analyzeThisMethodSpread()` already re-parses that declaring file for the direct spread case,
so no extra resolution step was needed to make the transitive case work.

A cycle (`a()` returning `$this->b()`, `b()` returning `$this->a()`) is not a new risk: the recursion
goes through the same `$visitedSpreadMethods` guard described above, so it degrades to an empty
analysis on re-entry rather than recursing until memory runs out. See `BareMethodReturnResource` in
the workbench and the corresponding test in `ResourceAstAnalyzerTest` for the transitive case
(`toArray()` → `data()` → `nested()`).

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

- **`whenNotNull()`** (`stripNull: true`) analyzes argument 0, then strips a top-level `| null` arm from its
  type via `stripNullArm()`: the `! is_null($value)` guard on the success arm proves that arm unreachable,
  so `whenNotNull($this->description)` emits `?string`, not `?string | null`.
- **`whenNull()`** (`stripNull: false`) forces argument 0's contribution to the literal string `'null'`
  instead of analyzing it — the success arm always returns `null` when the guard holds, so the value's own
  type is irrelevant to what the property can be.

### `stripNullArm()` only drops the top-level `null` arm

`stripNullArm()` splits the type on `LaravelTsPublish::splitTopLevelUnion()`, a depth-aware splitter over
braces, parens, angle brackets, and square brackets, and filters out a member equal to exactly `'null'`.
Only a union member sitting at depth zero is ever removed — `(string | null)[]` and `{ a: string; b: number
| null }` both keep their nested `| null` untouched, since neither nested `null` is a top-level member of
the outer type. `analyzeCoalesce()` calls the same `stripNullArm()` helper to strip the left operand of
`??`, so the two call sites can't drift out of sync. One consequence: a left operand of exactly `null`
(`null ?? $x`) strips to `'unknown'` and falls through to the right arm, since `null ?? $x` always
evaluates to `$x`.

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
`ImageResource`, `AddressResource`, …). When an explicit default *is* present, both the `optional` flag and
the union are decided by the shared `applyConditionalDefault()` helper described
[below](#every-handler-unions-the-default-arm-in-through-applyconditionaldefault), which `whenNotNull()` and
`whenNull()` reach with `$index: 1`. The default's own type is analyzed independently via
`analyzeValueExpression()`, since PHP evaluates it eagerly as an argument regardless of which arm ultimately
wins at runtime — unless the default is a closure requiring a parameter, none of which this pair ever
supplies, in which case it is never analyzed at all; see below.

### A default closure requiring more parameters than Laravel supplies is unreachable and never analyzed

Laravel invokes *almost* every conditional default via `value($default)` with **zero** arguments (verified
against `ConditionallyLoadsAttributes.php` in the installed Laravel 13 vendor tree): `when()` calls it
directly, `whenNull()`/`whenNotNull()` delegate to `when()`, and `whenLoaded()`, `whenHas()`,
`whenCounted()`, `whenAggregated()`, `whenExistsLoaded()` each call `value($default)` on their own miss
path. `transform()` is the one exception: it delegates to the global `transform()` helper
(`Support/helpers.php`), whose miss path calls an unfilled default as `$default($value)` — **one**
argument, not zero. A closure or arrow function passed as the default that declares more required
parameters than its caller actually supplies therefore throws `ArgumentCountError` at runtime instead of
producing a value — it can never contribute to the property's type.

`applyConditionalDefault($value, $call, $index, $defaultArgCount = 0)` checks this before analyzing the
default expression at all: `InspectsAstNodes::closureRequiresArguments(Expr $expr, int $providedArgs = 0)`
returns `true` when `$expr` is a `Closure` or `ArrowFunction` whose count of parameters lacking both a
default value and a variadic marker exceeds `$providedArgs`. Every handler leaves `$defaultArgCount` at its
default of `0` except `analyzeTransform()`, which passes `defaultArgCount: 1` to match the global helper's
one-argument call. When the check trips, `applyConditionalDefault()` returns the value arm's result alone
with `optional: false` — the same "unresolved default leaves the value arm standing" policy used elsewhere
in this helper — without ever calling `analyzeValueExpression()` on the default's body.

The check sits at `applyConditionalDefault()`, the one choke point every handler's default argument passes
through, with the per-method argument count as an explicit parameter rather than a special case. It does
not need to special-case the per-method `value($value, $resolved)` asymmetry described
[below](#unlessmergeunless-delegate-whenappended-whenexistsloaded-and-transform-are-new-handlers): that
asymmetry only affects *value*-arm closures (which receive the resolved value as an argument), and
value-arm closures never reach `applyConditionalDefault()` — only the default argument does. A parameter
with its own default (`fn ($notes = '') => strlen($notes)`) or a variadic parameter (`fn (...$args) => ...`)
still invokes cleanly with zero (or `$defaultArgCount`) arguments, so closures shaped like those are not
excluded and still union their return type in as usual.

### An empty-array default collapses instead of widening the property

`analyzeInlineArray()` discriminates on `$array->items === []` before it consults the extracted
properties, because the two empty results mean different things. A literal `[]` is `never[]` —
`json_encode([])` emits `[]`, never `{}`, so the old blanket `Record<string, unknown>` described a shape
the runtime could not produce. An array whose *keys* failed to resolve keeps the `Record` fallback, which
is still the honest answer there.

`applyConditionalDefault()` then drops a `never[]` member whenever another member is an array type. That
is sound precisely because the arm is provably empty: `[]` is assignable to every array type, so it adds
nothing beside a real one. `whenLoaded('children', $this->children, [])` is therefore `Category[]`, not
`Category[] | Record<string, unknown>` — a union whose second arm no caller could consume.

The two halves are coupled. The collapse keys on the literal `never[]`, and it is only sound because
`never[]` means *provably empty*. Retyping empty literals as `unknown[]` would break it: the rule could no
longer tell a provably-empty arm from a genuinely-unknown-element one, and `X[] | unknown[]` → `X[]` is
unsound. `never[]` is also the friendlier of the two for consumers — it is assignable to every array type,
where `unknown[]` is assignable to none.

### Dropping an `unknown` arm is a deliberate policy, not an incidental side effect

The `unknown`-filtering is recorded here explicitly because it is easy to mistake for defensive scaffolding
and simplify away in a later refactor — it is load-bearing. It fires whenever `analyzeValueExpression()`
fails to resolve **either** arm, and it has two justifications, not one:

- **Precedent.** `analyzeCoalesce()` already treats an `unknown` operand as "no information" rather than a
  real union member, and `analyzeClosureUnion()` does the same for an `unknown` return branch. Unioning
  `unknown` in literally here would be the odd one out.
- **`T | unknown` collapses to `unknown` in TypeScript.** The honest-looking alternative — emitting the raw,
  un-filtered union when one arm can't be resolved — wouldn't degrade gracefully to a *partial* type; it
  would degrade the *whole property* to `unknown`, which is exactly the outcome this package's own
  no-type-regressions-to-`unknown` discipline exists to prevent. Filtering the unresolvable arm out is what
  keeps a resolvable sibling arm's type surfacing at all.

What the filtering never does is change `optional`. Before the arity rule above existed,
`ConditionalParamFullClosureResource::status_resource` —
`whenNotNull($this->status, function ($status) { return EnumResource::make($status); })` — demonstrated the
unresolved-*default* direction: the closure's `$status` param was unbound, so `EnumResource::make($status)`
resolved to `unknown`, and the property emitted `OrderStatusType`, required, because the explicit second
argument still meant Laravel always emitted the key. That closure requires `$status`, though, so it is now
caught earlier by [the arity rule](#a-default-closure-requiring-more-parameters-than-laravel-supplies-is-unreachable-and-never-analyzed)
and never reaches `analyzeValueExpression()` at all — `OrderStatusType`, required, is correct by the same
evidence but for a different reason. `ConditionalDefaultsResource::pivot_loaded_with_default` still
exercises the unresolved-*value*-arm direction, via `whenPivotLoaded()`'s hard-coded `unknown` value arm:
`unknown`, also required.

### A model-level `#[TsCasts]` override can mask this fix in the final output

`AddressExtendsResource`/`AddressMixinResource` both call `whenNotNull($this->latitude)` — the value-arm fix
alone makes that `latitude?: number` at the analyzer level. It doesn't reach the generated `.ts`: `Address`
carries a model-level `#[TsCasts(['latitude' => ...])]` override, and
`ResourceTransformer::applyOverrides()` applies model-level `#[TsCasts]` *after* AST analysis, so it wins
regardless of what this handler determines. That is expected, unrelated behavior, not a regression — verify
the value-arm/default-arm split against a fixture whose properties carry no `#[TsCasts]` override (e.g.
`ConditionalDefaultsResource`, or `AddressResource`'s `line_2`) rather than against `latitude`/`longitude`.

## An explicit default makes the rest of the conditional family required too

Every method in the conditional family — `when()`, `whenHas()`, `whenLoaded()`, `whenCounted()`,
`whenAggregated()`, `whenPivotLoaded()`, `whenPivotLoadedAs()` — takes a trailing
`$default = new MissingValue` parameter (`whenNotNull()`/`whenNull()` already covered above). When a
caller passes one explicitly, the key can never be a `MissingValue`, so the property must not be
emitted `optional`. The default sits at a **different argument index per method** — verified against
`ConditionallyLoadsAttributes.php` — and that is the whole difficulty:

| method | signature | default index |
| --- | --- | --- |
| `when`, `unless`, `mergeWhen`, `mergeUnless` | `($condition, $value, $default)` | 2 |
| `whenHas`, `whenAppended`, `whenLoaded`, `whenCounted`, `whenExistsLoaded` | `($attribute\|$relationship, $value, $default)` | 2 |
| `whenNull`, `whenNotNull` | `($value, $default)` | 1 |
| `whenPivotLoaded` | `($table, $value, $default)` | 2 |
| `whenPivotLoadedAs` | `($accessor, $table, $value, $default)` | 3 |
| `whenAggregated` | `($relationship, $column, $aggregate, $value, $default)` | 4 |
| `transform` | `($value, $callback, $default)` | 2 |

Every handler passes its own `N` to `applyConditionalDefault()`, which asks
`hasExplicitDefaultArg($call, N)` first. That check is purely positional: Laravel distinguishes an
omitted argument from an explicitly-passed `null` via `func_num_args()`, not `=== null`, so a
`ConstFetch(null)` at the default position still counts as a real default —
`whenLoaded('user', fn ($user) => $user, null)` is required and typed `User | null`, not optional and typed
`User` (see `loaded_with_default` in `ConditionalDefaultsResource`). A named or spread argument at the
default position makes position meaningless, so the helper bails out to `false` — the property behaves as
if no default were passed at all.

### Every handler unions the default arm in, through `applyConditionalDefault()`

`applyConditionalDefault($value, $call, $index)` is the single vehicle for the whole family: every
handler builds its value arm, then hands it over with its own default index. It union-merges the two
arms' `' | '` members, deduplicates them, and folds their import channels via `mergeUnionChannels()`,
re-asserting `optional` to `false` afterwards (`mergeUnionChannels()` resets it). The merge is not
cosmetic — a default like `Status::Draft` or `UserResource::make(...)` carries an import channel that
only `mergeUnionChannels()` preserves; concatenating type strings by hand would emit a type name with no
import and trip the unimportable-token gate.

The settled rule is one sentence:

> An explicit default always makes the property required. Union the default's type in when it resolves;
> emit the value-arm type alone when it does not.

`optional` is decided by presence, not by type. Passing a default means the key is always emitted at
runtime, so `required` is factually true regardless of how well either arm resolved — forcing a consumer
through a presence check for a key that always exists would be its own kind of lie. That is why the single
early return sets `optional` to `false` rather than backing out to `true`.

It covers both directions of an `'unknown'` arm, which reach the same result for different reasons:

- **The default resolves to `unknown`** (an unanalyzable expression or closure body). There is no type to
  union, so the value arm's own type stands alone — the `'unknown'` arm is dropped rather than unioned in
  literally, the same treatment `analyzeCoalesce()` gives an `'unknown'` operand. Unioning it in would
  collapse the whole property to `unknown`, which the no-type-regressions-to-`unknown` discipline exists to
  prevent.
- **The value arm is `unknown`.** `unknown` already admits every value the default can produce, so the
  union collapses back to `unknown`. Narrowing to the default's type alone would drop whatever the value
  arm can still return. This is the `whenPivotLoaded()` / `whenPivotLoadedAs()` case, whose value arm is a
  hard-coded `unknown` the handler never inspects.

Routing the whole family through one helper replaced three near-identical inline merge blocks and closed a
soundness hole: `analyzeWhenHas()`, `analyzeWhenAppended()`, `analyzeWhenLoaded()` and
`analyzeWhenExistsLoaded()`, plus the `whenCounted()`/`whenAggregated()` inline arms, used to flip **only**
`optional` and emit the value arm's type alone. `$this->whenLoaded('user', fn ($user) => $user, null)` was
emitted as a required `User`, so a consumer could dereference the very `null` Laravel returns when the
relation is not loaded. Note that `whenLoaded()`, `whenHas()` and `whenAppended()` do already analyze
`$args[0]`/`$args[1]`, so reading one more argument was never the obstacle it was once described as.

### `whenPivotLoaded()`/`whenPivotLoadedAs()` need separate `if` branches, not a combined one

Before this task, both were handled by one `isThisMethodCall(...) || isThisMethodCall(...)` branch,
because they shared identical output (`unknown`, always optional). Once `optional` depends on the
default's argument index, that combined branch stops working: `whenPivotLoadedAs()` takes a leading
`$accessor` argument that `whenPivotLoaded()` doesn't, so their default sits at index 3 vs. index 2.
Each method needs its own branch reading its own index.

## `unless()`/`mergeUnless()` delegate; `whenAppended()`, `whenExistsLoaded()`, and `transform()` are new handlers

Five methods in the conditional family had no handler at all before this task and fell through to the
generic `$this->method()` branch, which reflects a declared return type and emits **required** `unknown` —
strictly worse than the optional `unknown` an unrecognized conditional should produce, since a required
key tells the consumer the value is always present.

All five (`unless`, `whenAppended`, `whenExistsLoaded`, `transform`, `mergeUnless`) also belong to
`InspectsAstNodes::$conditionalMethods`, the separate list consulted when one of them wraps a *nested
resource constructor* (`Resource::make(...)`/`new Resource(...)`), so that case is optional too.

### `unless()` and `mergeUnless()` reuse `when()`/`mergeWhen()` unchanged

`ConditionallyLoadsAttributes::unless($condition, $value, $default)` is
`$this->when(! $condition, $value, $default)`, and `mergeUnless()` is
`$this->mergeWhen(! $condition, $value, $default)` — Laravel negates the condition and forwards straight
through. Negating which branch of an `if` runs never changes what either branch's *type* is, so
`analyzeValueExpression()` dispatches `unless` straight to the existing `analyzeWhen()`, and
`analyzeMergeExpression()` treats `mergeUnless` exactly like `mergeWhen()` (array/closure argument at index
1, always optional). Neither needed a new method.

### `whenAppended()` types from the named attribute, like `whenHas()`

`whenAppended('attribute', $value, $default)` mirrors `analyzeWhenHas()`: `analyzeWhenAppended()` resolves
the accessor's type via `resolveModelAttributeTypeInfo()` from the attribute name alone, never from
analyzing `$value`. This matters because Laravel's `whenAppended()` does **not** forward the resolved value
into a `$value` closure the way `whenHas()`/`whenLoaded()`/`whenCounted()`/`whenAggregated()`/
`whenExistsLoaded()` do — it calls `value($value)` with zero arguments, not `value($value, $resolved)` — so
a `$value` closure parameter has nothing bound to it in Laravel's own implementation. Typing from the
attribute name sidesteps that distinction entirely. The *default* at index 2 is still analyzed and unioned
in by `applyConditionalDefault()` — it is a plain eagerly-evaluated argument, not a closure needing a
binding.

### `whenExistsLoaded()` resolves to the generated `{relation}_exists` flag — and must agree with `ModelTransformer`

`whenExistsLoaded('relation')` reads `Model::withExists()`'s `{relation}_exists` attribute — the same flag
`ModelAttributeResolver::resolveAttributeFallbacks()` types as `boolean` for a model's own `*_exists`
properties (the `_exists` suffix → `boolean` fallback, mirroring `_count` → `number`).
`analyzeWhenExistsLoaded()` emits that same `boolean`, deliberately: a resource and the model it wraps
disagreeing about the type of the same underlying flag is exactly the kind of divergence this package
exists to prevent. An explicit default still unions its own type alongside that `boolean`, since the
runtime can return it in place of the flag.

### `transform()` types from the callback's return, not `$value`'s

`transform($value, $callback, $default)` (`vendor/laravel/framework/.../Support/helpers.php`) calls
`$callback($value)` when `$value` is filled and returns that result — the callback's return type, not
`$value`'s own type, is what the property carries. `analyzeTransform()` mirrors `analyzeWhen()`'s
value-argument handling but analyzes `$args[1]` (the callback) instead of `$args[0]`, binding the
callback's first parameter to `$args[0]`'s `$this->prop` expression via `bindClosureParamsFromCondition()`
the same way `analyzeWhen()` binds a value closure to its condition, then hands the result to
`applyConditionalDefault()` with index 2, exactly like `analyzeWhen()` does — except for
`defaultArgCount: 1`: the same `transform()` helper invokes an unfilled default as `$default($value)`,
one argument, not the `value($default)`/zero-argument call every other handler's default receives (see
[above](#a-default-closure-requiring-more-parameters-than-laravel-supplies-is-unreachable-and-never-analyzed)).
A one-parameter closure default is therefore reachable here and unions in, where the same shape would be
excluded as unreachable anywhere else in the family.

## `#[Collects]` resolution is Laravel-version-guarded

`collectedResourceClass()` checks for `Illuminate\Http\Resources\Attributes\Collects` behind
`class_exists()` rather than a `use` import, because the package still supports Laravel 12 releases
that don't ship the attribute. See [Version-guarded Laravel
classes](../laravel-version-guards.md) for the full registry and when this guard can be removed.

## `#[PreserveKeys]`/`$preserveKeys` flip a collection's element type to `Record<string, R>`

A `ResourceCollection` normally serializes as a JSON array, so a collected element type gets a `[]`
suffix. Laravel honours two ways of opting a collection out of that: the `#[PreserveKeys]` class
attribute (Laravel 13+) and the older `public $preserveKeys = true;` property (every supported
version). Either one makes Laravel keep the collection's original keys, so the payload is a JSON
object instead — `collectionPreservesKeys()` checks both, and `wrapCollectionElementType()` is the
single point that turns that boolean into `Record<string, R>` instead of `R[]`.

Every collection-typing call site routes through `wrapCollectionElementType()`, at six emission
points across two paths:

- **`SomeResource::collection(...)` / `SomeCollection::make()`/`::collection()` / `new
  SomeCollection(...)`, referenced inside another resource's `toArray()`** — three sites, in
  `analyzeStaticCall()` (two: the named-collection branch and the plain-resource branch) and
  `analyzeNewResource()` (one).
- **A `ResourceCollection` with no `toArray()` override, delegating to `$this->collection`** —
  three sites, in `buildCollectionDelegatedAnalysis()` (the `flatTypeAlias` branch and the
  wrapped-`data`-key branch, which share one computed element type) and `analyzeCollectionProperty()`
  (the `$this->collection` property read).

That count is exactly what a future change to any of these methods is liable to get wrong — a site
that's missed silently keeps emitting `R[]`, and nothing catches it until a fixture exercises that
exact call shape with `#[PreserveKeys]` or `$preserveKeys` set.

The reflection target passed to `wrapCollectionElementType()` differs by site to match what Laravel
itself reflects on at runtime: for `SomeResource::collection(...)`, Laravel's `JsonResource::collection()`
checks `static::class` — the singular resource being called on, not a separate collection class — so
that site reflects on the resource. Every other site reflects on the `ResourceCollection` subclass
itself, since that's what Laravel instantiates and reflects on for `make()`, `new`, and the
collection-delegated path.

### Inertia props

`collectionPreservesKeys()` and `wrapCollectionElementType()` live in the
`AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\ChecksPreserveKeys` trait, which both this class
and `AbeTwoThree\LaravelTsPublish\Analyzers\Inertia\InertiaPageAnalyzer` `use`. `InertiaPageAnalyzer`
has its own four collection-typing rewrites for `Inertia::render()` page props — paginated and
non-paginated, named and anonymous — and preserve-keys only changes two of them:

- **`rewritePaginatedResourceProps()`'s flat branch** (`$wrap === null`, e.g. `new
  SomeFlatCollection($paginator)`) and **`rewritePaginatedStaticCollectionProps()`** (`SomeResource::collection($paginator)`)
  both emit `JsonResourcePaginator<Singular>` by default, whose `data` member is `Singular[]` — wrong
  for a key-preserving collection, since Laravel serializes its `data` as an object, not an array.
  Fixed to emit `Omit<JsonResourcePaginator<Singular>, 'data'> & { data: Record<string, Singular> }`
  when `collectionPreservesKeys()` is true, gated on the reflected collection (flat branch) or the
  reflected resource (static-collection branch, since `Resource::collection()` inherits the singular
  resource's own preserve-keys state — mirroring `wrapCollectionElementType()`'s own site-dependent
  reflection target above).
- **`rewriteResourceCollections()`** (the bare named-collection case) and **the wrapped, non-flat
  branch of `rewritePaginatedResourceProps()`** were already correct and are unchanged: both reference
  the collection's own generated interface — e.g. `PostCollection` — rather than re-deriving a shape,
  and `ResourceAstAnalyzer` already emits that interface with a keyed `data: Record<string, T>` member
  via `wrapCollectionElementType()` when the collection preserves keys. Fixtures pin this: a
  key-preserving named collection, paginated or not, produces identical output before and after this
  change.

## `mergeReturnBranches()` carries every `syncAnalysisMaps()` channel, plus two flat scalars

A resource with multiple direct `return [...]` branches (`if`/`elseif`/`else`, loop bodies, guard
clauses) is analyzed per-branch, then unioned by `mergeReturnBranches()`. It carries the same nine
channels `syncAnalysisMaps()` does — `properties`, `enumResources`, `nestedResources`,
`directEnumFqcns`, `modelFqcns`, `customImports`, `multiEnumResourceFqcns`, and the three inline
maps (`inlineEnumFqcns`, `inlineModelFqcns`, `inlineEnumResourceFqcns`) — unioning each inline map
per property key, exactly like `syncAnalysisMaps()`. Missing this union silently drops a property's
only enum/model reference when that reference sits inside an inline array literal in one branch,
emitting a type token with no import.

It additionally resolves `flatTypeAlias`/`flatTypeAliasFqcn`, two scalars `syncAnalysisMaps()` never
touches: the first non-null branch wins on conflict. No fixture exercises that conflict rule —
`analyzeReturnArray()` never sets either field, so every branch reaching this method already has
both null; only `buildCollectionDelegatedAnalysis()` sets them, and it returns directly without
going through branch merging.
