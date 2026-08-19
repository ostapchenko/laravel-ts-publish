# ModelAttributeResolver

> User-facing docs: [README § Models](../../README.md#models) (see especially the
> [annotation checklist](https://tolki.abe.dev/ts/models.html#annotation-checklist)). Verified by
> [the type-inference gates](../testing/type-inference-gates.md).

`AbeTwoThree\LaravelTsPublish\ModelAttributeResolver` resolves a model attribute's TypeScript
type through the accessor → cast → DB type waterfall, and resolves arbitrary method return
types (relation-filter methods, `$this->method()` resource calls) via
`LaravelTsPublish::methodOrDocblockReturnTypes()`.

## Resolution order: signature → docblock-when-vague → fallback

`methodOrDocblockReturnTypes()` no longer treats a present native return type as automatically
final. A native PHP signature is often deliberately loose — `: array`, `: iterable`,
`: object`, `: mixed` — while the method's own docblock frequently carries the real shape via
`@return array{...}`, `@return list<...>`, or a generic container. The resolution order is:

1. **Signature, if specific.** `resolveReflectionType()` reflects the method's declared return
   type. If the resulting TS type is neither `unknown` nor "vague" (see below), it wins
   outright — the docblock is never even parsed. A signature declaring `: string` always beats
   a docblock, no matter what the docblock claims.
2. **Docblock, if it resolves to something non-vague.** Only when the signature is absent,
   unknown, or vague does `docblockReturnTypes()` get a chance. If its result is specific, it
   wins.
3. **Whichever of the two is non-`unknown`**, signature preferred, as a last-resort fallback.

```php
/** @return array{value: int, label: string} */
public function asAutoCompleteOption(): array
{
    return ['value' => (int) $this->getKey(), 'label' => (string) $this->notes];
}
```

`: array` resolves to the vague `unknown[]` on its own — with no docblock deferral, the
generated property would be typed `unknown[]`, discarding the shape entirely. Deferring to the
docblock resolves this to `{ value: number; label: string }` instead.

### What counts as "vague"

`isVagueTsType(string $type): bool` — a single predicate, defined once on `LaravelTsPublish`
(reused by `Concerns\ResolvesAccessorType`'s accessor-closure-vs-docblock check and by
`ModelAttributeResolver::refineWithPropertyDocblock()`'s `@property` refinement below, both of
which follow the same "keep looking while still vague" shape) — treats a type as vague when it
contains the literal substring `unknown` outside of any `{...}` object literal (covers `unknown`,
`unknown[]`, `unknown[] | Record<string, unknown>`, `Record<string, unknown>`, …) or is exactly
`object`. An object-literal shape is never vague on its own account, even when one of its own
keys resolves to `unknown` — `{ filters?: Record<string, unknown>; sorts?: string[] }` is
specific, because a `mixed`-typed key inside an otherwise-concrete shape is not the same thing as
having no shape at all. Anything else — `string`, `OrderItem[]`, `{ value: number; label: string }`
— is specific enough to win immediately.

## Arrayable/JsonSerializable shape-source precedence

`arrayableShapeType()` takes a `bool $fallbackToProperties` argument, and the two call sites in
`toTsType()` pass it differently — the shape-source chain is **not** identical for `Arrayable` and
`JsonSerializable`:

- **`Arrayable`/`toArray()`** (`$fallbackToProperties = true`, the default) resolves in this order,
  falling through only when a step yields nothing:
  1. **`@return array{...}` docblock** on `toArray()`, via `parseDocblockReturnArrayShape()`. A
     vague `@return array<string, mixed>` doesn't count — only a real `array{...}` shape wins here.
  2. **Typed public properties**, via `publicPropertyShapeType()` — the fallback for a DTO whose
     `toArray()` is just `(array) $this` with no shape docblock. Promoted constructor properties
     count as public properties for reflection purposes; private, protected, and static properties
     are skipped, since none of them are part of `(array) $this`. Each property resolves through
     `propertyTypes()` (reflection type, nullability appended), and a value carrying an
     unimportable class/enum token degrades to `unknown` via the same
     `shapeValueHasUnimportableToken()` check the docblock path uses.
  3. **`unknown[]`** — the class has neither a shape docblock nor any public instance properties.
- **`JsonSerializable`/`jsonSerialize()`** (`$fallbackToProperties = false`) resolves only from a
  `@return array{...}` docblock; when that's absent it falls through to the rest of `toTsType()`
  (class-basename, `__toString`, etc.) instead of `unknown[]` or a property-derived shape. Typed
  public properties are deliberately **not** consulted here: `(array) $this` is a real contract
  tying `toArray()`'s output to a DTO's own properties, but `jsonSerialize()` carries no such
  contract — it can return anything, unrelated to the object's properties — so inferring a shape
  from them would risk emitting a plausible-looking but wrong type instead of falling through
  conservatively.

`publicPropertyShapeType()` reuses `$shapeExpansionStack`, the same guard `arrayableShapeType()`
uses for docblock shape cycles, under a `"{FQCN}::__properties"` key distinct from the
`"{FQCN}::{method}"` docblock key — a property typed as the class itself, or a mutual A/B pair,
degrades the second-level reference to `unknown[]` instead of recursing until memory is exhausted.

## Nested array shapes inside generic containers

A docblock generic's value slot — the `X` in `list<X>`, `array<K, X>`, `Collection<K, X>` —
recurses through `resolveDocblockContainerValue()`. When that slot is itself an `array{...}`
shape (`list<array{key: string, label: string}>`), it now resolves through
`resolveArrayShapeString()` — the same shape resolver `resolvePhpDocTypeToTs()`'s `array{`
branch and `resolveDocblockTypePart()` (the top-level, non-generic docblock path) call — instead
of falling through to a plain `toTsType()` string match, which would degrade a nested shape to a
second `unknown[]` layer (`unknown[][]`). One resolver, three call sites, so a shape formats
identically everywhere it is found in a docblock: `{ key: string; label: string }[]`, not
`unknown[][]`.

A shape value that resolves to an unimportable bare class/enum token (no import channel exists
for a string-only shape map) still degrades that one key to `unknown` — `resolveArrayShapeString()`
applies the same `shapeValueHasUnimportableToken()` check `arrayableShapeType()` uses — so only
the unresolvable leaf is lost, not the whole shape.

## Nullable-prefixed generics

`resolveGenericContainerType()` strips a leading `?` before attempting to match a container
(`?array<int, int>`, `?Collection<int, X>`), resolves the remainder as usual, and appends
`| null` to the result (skipped if already nullable) — mirroring `toTsType()`'s own step 0 for
the non-generic case. Without this, the `?` prefix stopped the container regex from matching at
all, so the whole generic fell through to `toTsType()`'s partial string matching and produced a
plausible-but-wrong scalar or an unwrapped `unknown`.

```php
/** @return Attribute<?array<int, int>, never> */
protected function stateIds(): Attribute
{
    return Attribute::get(fn () => null);
}
```

Resolves to `number[] | null`, not `unknown[] | null`.

## `@phpstan-type` / `@phpstan-import-type` alias resolution

`LaravelTsPublish::resolvePhpstanTypeAlias(string $name, ReflectionClass $context): array{definition:
string, class: ReflectionClass<object>}|null` looks up a phpstan/psalm type alias visible from
`$context`, in this order:

1. **Local definition** — a `@phpstan-type Name <definition>` (or `@psalm-type`) tag on
   `$context`'s own docblock. A definition whose `{}`/`<>` depth is unbalanced on its own line is
   walked across subsequent docblock lines (`balanceTypeDefinition()`) until it closes, so a
   multi-line `array{...}` shape is captured whole rather than truncated at the first line.
2. **Imported definition** — a `@phpstan-import-type Name from OtherClass` (or `... as Alias`) tag,
   resolved by recursing into `OtherClass`'s own `resolvePhpstanTypeAlias()` call. This is what
   makes resolution **transitive**: an import chain of aliases each re-exporting the next resolves
   all the way to the class that actually wrote the shape.

A `$seen` set of `"{FQCN}::{name}"` keys guards the import recursion — the same "already
expanding" pattern `arrayableShapeType()` uses for `Arrayable`/`JsonSerializable` shape cycles.
Two classes whose aliases `@phpstan-import-type` each other terminate with `null` (degrading the
referencing property to `unknown`) instead of recursing forever. Results are cached per class.

The returned `class` is the alias's **defining** class, not `$context` — its own use-map and
namespace, not the referencing class's, resolve any class names inside the definition. This
matters once the definition is expanded: `resolveDocblockTypePartOrAlias()` (the wiring used by
both `docblockReturnTypes()`/`resolveDocblockTypeString()` and
`refineWithPropertyDocblock()` below) tries `resolvePhpstanTypeAlias()` for each union member
before falling through to the ordinary generic/shape/class pipeline, and on a hit resolves the raw
definition (`resolveDocblockPartToInfo()`) against the alias's own file — deliberately *not*
alias-aware again, so two purely local aliases naming each other can't open an unguarded second
recursion path outside the `$seen`-guarded import chain.

`@property`'s own array-shape parser (`parseArrayShapeToTsTypes()`) keeps a key's `?` as part of
its returned map key (`'filters?'` rather than `'filters'`), so an alias expanding to
`array{filters?: ...}` emits `filters?: ...` in the generated interface instead of silently
dropping optionality.

## Castable-with-arguments cast strings

`resolveAttribute()` passes a model's raw cast value straight to `LaravelTsPublish::toTsType()`, including
compound `Castable` strings built by Laravel's own `AsEnumCollection::of()`/`AsCollection::of()`/`::using()`
helpers — these encode their arguments after the *first* colon (`"Illuminate\...\AsEnumCollection:App\Enums\Status"`),
since an argument is itself a class name and may contain `\`. `toTsType()`'s cast-string step (`resolveCastWithArguments()`)
splits on that first colon only and dispatches on the head:

| Head class | Args (comma-separated after `:`) | Resolves to |
| --- | --- | --- |
| `AsEnumCollection` (or subclass) | `[enumClass]` | the enum's TS type suffixed `[]`, `enumFqcns` populated for the import — identical wiring to a scalar enum-typed column |
| `AsCollection` (or subclass) | `[collectionClass, mapClass]` (either may be empty) | `mapClass`'s resolved element, suffixed `[]`, when it resolves to an inline `{...}` shape or an enum; `unknown[]` when `mapClass` is absent or resolves to anything else |
| any other existing `Castable`/`CastsAttributes` class | (ignored) | `toTsType($head)` — resolved exactly as the bare class, arguments stripped |
| a head that is not an existing class (`"decimal:2"`, `"encrypted:array"`) | — | untouched; falls through to the DB-type/`encrypted:` steps further down the waterfall |

The `AsCollection` branch only trusts an inline `{...}` shape or an enum as the array element — a bare unpublished
class token has no import channel, so that case degrades to `unknown[]` rather than emit an identifier nothing
imports.

## The DB-native type map is keyed on the grammar's output, not the migration method

When a column has no cast, `resolveAttribute()` falls to `LaravelTsPublish::toTsType($attr['type'])`
with `ModelInspector`'s raw `Schema::getColumns()` value — the native string a schema grammar emits
(`tinyint(1)`, or `point` for a MySQL `geometry(subtype: 'point')` column), never the migration method
name (`boolean()`, `geometry()`) — so `vendor/laravel/framework/.../Schema/Grammars/*Grammar.php`, not
vendor SQL documentation or the `$table->column()` method list, is the authoritative source for a new
`TypeScriptMap` entry.

## `@property` refinement: search order, trait walk, and the acceptance rule

`refineWithPropertyDocblock(ReflectionClass $reflection, string $attributeName, array $tsInfo):
array` only runs at all when `$tsInfo['type']` is already vague (see above) — a concrete waterfall
result is never second-guessed. When it does run, `propertyDocblockClasses()` builds the search
order: the class/parent chain first (child tag wins over parent), then every trait used anywhere in
that chain, recursively via `collectTraitsRecursively()` (`ReflectionClass::getTraits()`, walked
again on each trait for traits-of-traits). A trait is checked only after the whole
inheritance chain has been exhausted, so a real class-level tag always wins over one living on a
trait the class happens to use.

For each candidate class, `refineFromClassDocblock()` looks for an `@property`/`@property-read` tag
naming `$attributeName`. The `$` sigil before the property name is **optional** in the tag regex —
a non-standard but real-world convention (`@property string[] tag_names`, no `$`) some packages
use on a trait's own class docblock. This is safe to widen because the type-capture group still
excludes the literal `$` character entirely: a genuine `@property Type $name Description` line
still can't be walked through by a *different* attribute's search, since the type capture cannot
cross the `$` that marks the real variable, regardless of whether the sigil is required at the
match's own end. Only a docblock tag that never contains `$` anywhere on its line — the exact,
narrow case the optional sigil exists for — is affected by the relaxation.

### Acceptance rule: `isStrictlyMoreStructured()`

A refinement candidate is only used when it clears `isStrictlyMoreStructured(string $candidate,
string $current): bool`:

- A candidate that isn't vague at all (no `unknown`, not bare `object`) is always accepted.
- A candidate that *is* still vague is accepted only when **both** hold:
  1. `$current` — the type being replaced — is *entirely* vague: exactly `unknown`, `unknown[]`,
     `object`, or the `unknown[] | Record<string, unknown>` Collection fallback, each optionally
     suffixed `| null` (`isEntirelyVagueTsType()`).
  2. `$candidate` is not itself in that same entirely-vague set.

This is deliberately narrower than "not vague" — a bare `Record<string, unknown>` (vague per
`isVagueTsType()`, since it names `unknown` outside any `{...}`) still beats a bare `unknown[]`,
because it at least commits to a dictionary shape the original carried no information about at
all. But it does **not** beat another already-somewhat-structured vague type: refining
`Record<string, unknown>` itself, or refining into the Collection fallback
`unknown[] | Record<string, unknown>`, is rejected, since neither side of that comparison is
"entirely" vague to begin with.

```php
/** @property array<string, mixed>|null $settings */
class Team extends Model
{
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
```

`settings` resolves through the `'array'` cast to `unknown[]` first — entirely vague — so the tag's
`Record<string, unknown> | null` is accepted even though it still names `unknown`, and `settings`
generates as `Record<string, unknown> | null` rather than `unknown[] | null`.

## MorphTo target resolution: docblock generic → reverse map keyed by morph name

`resolveMorphToTargets(string $modelFqcn, string $relationName): list<class-string>` is the
single entry point both `resolveRelation()` and `ModelTransformer::transformRelations()` call for
a `MorphTo` relation's target union — a duplicate ad hoc implementation in `ModelTransformer` was
exactly how a docblock generic went unread by the actual publish pipeline even after
`resolveRelation()` itself honored it, which is why only one method owns this now. Resolution
order:

1. **`morphToDocblockTargets()`** — a `@return`/`@phpstan-return MorphTo<X|Y, ...>` generic on the
   relation method. Only the *first* generic argument is read (the second, `$this` by Laravel's
   own convention, names the child and carries no target information). Each union member is
   resolved to a FQCN through `methodDeclaringFileClass()`'s use-map (see below — critical for a
   trait-provided relation) and must be an existing, concrete `Model` subclass; a bare `Model` or
   an abstract class anywhere in the union makes the *whole* generic non-narrowing (`[]`), since a
   `MorphTo<Model, $this>` generic is Larastan's own placeholder for "targets not statically
   known" and emitting a literal `Model` token would be a useless, unimportable-in-spirit result.
2. **The reverse-relation map, keyed by morph name.** When the docblock is absent or non-narrowing,
   `relationMorphName()` invokes the relation method on an unpersisted instance (safe — building
   an Eloquent relation only appends to a query builder, it never queries) to read
   `MorphTo::getMorphType()` (`'imageable_type'` minus the `_type` suffix → `'imageable'`), then
   looks up `getMorphToTargets($modelFqcn, $morphName)`.

`buildMorphTargetMap()` writes two keys per parent relation found: `childFqcn.'|'.morphName` (so
two differently-named `morphTo`s on the same child model — e.g. `Activity::causer` and
`Activity::subject` — don't share a union) and the plain `childFqcn` as a legacy aggregate bucket.
`getMorphToTargets()` tries the specific key first and falls back to the plain bucket, so a model
whose own morph name can't be determined (constructor throws, `getMorphType()` unavailable)
degrades to the old, model-wide behavior instead of losing the union to `unknown`. A model with
exactly one `morphTo` relation is unaffected either way, since its keyed and legacy buckets always
hold the same parents.

## An unresolved MorphTo stays bare `unknown`, never `unknown | null`

When a `MorphTo` has no targets — the docblock generic is absent/non-narrowing and the reverse map
finds no parent — `buildMorphUnionInfo()` types it `'unknown'`. `unknown` already admits `null`, so
appending `' | null'` for a nullable FK would only add a redundant union arm; `buildMorphUnionInfo()`
skips the suffix whenever the resolved base type is exactly `'unknown'`.

`ModelTransformer::transformRelations()` does not call `buildMorphUnionInfo()` — it re-derives the
same union from `resolveMorphToTargets()` and applies its own nullable suffix, because the model's
own interface generation predates `resolveRelation()`'s consolidation (see the section above). The
same `!== 'unknown'` guard is applied there independently, so a model's own MorphTo relation and a
resource's inline reference to it never disagree on this.

## Declaring-file use-maps for trait-provided methods

Every docblock resolution that needs "the use-map/namespace of the file that wrote this
docblock" — `docblockReturnTypes()`, `resolveDocblockTypeString()`,
`parseDocblockReturnArrayShape()`, `ModelAttributeResolver::morphToDocblockTargets()` — resolves
that file via `methodDeclaringFileClass()`, not `ReflectionMethod::getDeclaringClass()` directly.
For a method a class picks up from a `use`d trait, PHP's own `getDeclaringClass()` reports the
*consuming* class, not the trait — a
long-standing reflection quirk, since traits are flattened into the class at compile time — even
though `getFileName()` and `getDocComment()` still read from the trait's own source.
`methodDeclaringFileClass()` compares the method's real file (`getFileName()`) against the
declaring class's file, and when they differ, walks the declaring class's traits (recursively, for
traits-of-traits via `flattenTraits()`) to find the one whose file matches. Left unfixed, a class
name in a trait-declared accessor's docblock — `@return Attribute<Collection<int, OptionValue>,
never>` — would resolve against the *consuming* model's imports instead of the trait's, silently
degrading to `unknown` whenever the model doesn't happen to import the same class.

## `publishedColumnNames()` and the `exclude_hidden` coupling

`databaseColumnNames()` is the raw schema listing (every real column, `$hidden` included).
`publishedColumnNames()` is the subset that actually reaches the emitted model interface — the
list a caller must use when naming keys against that interface, e.g. `ResourceAstAnalyzer`
deciding whether `$this->relation->only(['a', 'b'])` can reference `Pick<Model, 'a' | 'b'>`
instead of expanding inline (see [ResourceAstAnalyzer § When a Pick/Omit reference is
emitted](resource-ast-analyzer.md#when-a-pickomit-reference-is-emitted)).

Which of the two a call site wants turns on the question it is asking. `publishedColumnNames()`
answers "may I name this key against the generated interface?", so it must track what
`transformColumns()` emitted. `databaseColumnNames()` answers "is this name a real column at all?",
which is what the runtime-fidelity call sites need and why `$hidden` membership is irrelevant to
them: `ResourceAstAnalyzer::resolveFilteredRelationType()`'s except branch intersects the related
model's attribute list with it so an inlined `$this->relation->except([...])` expands to columns
only, matching `HasAttributes::except()`, and `buildModelDelegatedAnalysis()` reads it as
`$dbColumns` to keep `isOmittedMutator()` from ever dropping a real column. Both apply
`excludeHiddenAttributes()` themselves, separately from the listing, so they get the hidden-column
rule without borrowing `publishedColumnNames()`' interface-compatibility rule.

Whether the two lists actually differ is controlled by `ts-publish.models.exclude_hidden`
(`excludeHiddenAttributes()`), which defaults to `false`: `$hidden` attributes are published by
default, so `databaseColumnNames()` and `publishedColumnNames()` agree for every model unless the
setting is turned on. `ModelTransformer::transformColumns()` reads the identical flag through the
same method to decide whether to skip a `$hidden` attribute when building the interface.

Both call sites must agree, because `Pick<Model, K>` constrains `K extends keyof Model` — TypeScript
error TS2344 fires if `publishedColumnNames()` ever names a key `transformColumns()` didn't emit.
`excludeHiddenAttributes()` is the single source of truth both sites read, and it is deliberately
**not** cached alongside `resolveContext()`'s per-FQCN model context: that cache holds data that's
inherent to the model (its columns, casts, hidden-array membership) and is safe to memoize for the
life of the resolver, but the config flag can change between calls (most concretely in a test that
flips `config()->set('ts-publish.models.exclude_hidden', ...)` between two assertions on the same
resolver instance) and must be re-read every time rather than trapped in that cache.

## `#[UseResource]` model-guessing is Laravel-version-guarded

`ResourceTransformer::guessModelFromUseResourceAttribute()` — the counterpart lookup that finds
which model a resource belongs to — checks for `Illuminate\Database\Eloquent\Attributes\UseResource`
behind `class_exists()` rather than a `use` import, because the package still supports Laravel 12
releases older than 12.29 that don't ship the attribute. See [Version-guarded Laravel
classes](../laravel-version-guards.md) for the full registry and when this guard can be removed.

## Laravel 13's `#[Table]`/`#[Hidden]`/`#[Visible]`/`#[Appends]`/`#[Connection]` work for free

These five Laravel 13 class attributes change how a model reports its table, its hidden/visible
columns, its appended accessors and its connection — and this package needed **no dedicated code**
to honour any of them. Laravel resolves each attribute itself, inside `Model::__construct()`, via
`initializeModelAttributes()` (`#[Table]`, `#[Connection]`) and the `#[Initialize]`-marked
`initializeHidesAttributes()` (`#[Hidden]`, `#[Visible]`) and `initializeHasAttributes()`
(`#[Appends]`) — by the time this package ever sees a model, the attribute has already been folded
into that instance's ordinary state (`$table`/`$connection`/`$hidden`/`$visible`/`$appends`).

This package only ever reads that state back through plain instance calls, the same four call
sites that already made `protected $table = '...'` etc. work before Laravel 13 existed:

- `ModelTransformer::initInstance()` (`src/Transformers/ModelTransformer.php:182`) calls
  `$this->modelInstance->getConnection()->getSchemaBuilder()->getColumnListing($this->modelInstance->getTable())`
  — honours `#[Table]` and `#[Connection]` together, since both feed into which schema is queried
  for which table name.
- `ModelTransformer::initInstance()` (`src/Transformers/ModelTransformer.php:184`) calls
  `$this->modelInstance->getAppends()` — honours `#[Appends]`.
- `ModelAttributeResolver` (`src/ModelAttributeResolver.php:487`) calls
  `$ctx['instance']->getTable()` and `getConnection()` again when resolving a column's type.
- `ModelInspector::getAttributes()` (Laravel's own, in `vendor/laravel/framework/.../ModelInspector.php`,
  which `AbeTwoThree\LaravelTsPublish\ModelInspector` extends) calls `attributeIsHidden($column,
  $model)` against the **made instance** it inspects — `count($model->getHidden())` /
  `count($model->getVisible())` — honouring `#[Hidden]` and `#[Visible]` identically to the
  property-based conventions, because by then there is no distinction left to make.

**This is fragile in one specific way: it depends on working through instances, not static
reflection.** If a future refactor reads `#[Table]`/`#[Hidden]`/etc. directly off the class via
`ReflectionClass::getAttributes()` instead of asking a constructed instance for `getTable()` /
`getHidden()` / `getAppends()`, these five attributes stop working silently — the tests in
`ModelTransformerTest.php` would start failing, but nothing in their names or diffs points back to
this coupling. If you are that refactor: keep going through an instance, or update this section.

No `src/` code references these five attribute classes at all, so unlike `#[UseResource]` and
`#[Collects]` (see the section above), they need no `class_exists()` guard in `src/` and no
`use`-import ban there either. The workbench fixture models `use`-import `Table`/`Hidden`/
`Appends`/`Visible`/`Connection` normally, the same as any Laravel 13 application would — a `use`
statement is a compile-time alias, not an autoload, so it is inert on Laravel 12 for exactly the
same reason `getAttributes()` metadata is inert there: nothing calls `newInstance()` on it. Their
`class_exists()` reference lives only in each attribute's `->skip()` test guard in
`ModelTransformerTest.php`. See [Version-guarded Laravel
classes](../laravel-version-guards.md) for the test-only guard rows this implies.
