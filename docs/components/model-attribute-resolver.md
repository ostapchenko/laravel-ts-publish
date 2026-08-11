# ModelAttributeResolver

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

## Declaring-file use-maps for trait-provided methods

Every docblock resolution that needs "the use-map/namespace of the file that wrote this
docblock" — `docblockReturnTypes()`, `resolveDocblockTypeString()`,
`parseDocblockReturnArrayShape()` — resolves that file via `methodDeclaringFileClass()`, not
`ReflectionMethod::getDeclaringClass()` directly. For a method a class picks up from a `use`d
trait, PHP's own `getDeclaringClass()` reports the *consuming* class, not the trait — a
long-standing reflection quirk, since traits are flattened into the class at compile time — even
though `getFileName()` and `getDocComment()` still read from the trait's own source.
`methodDeclaringFileClass()` compares the method's real file (`getFileName()`) against the
declaring class's file, and when they differ, walks the declaring class's traits (recursively, for
traits-of-traits via `flattenTraits()`) to find the one whose file matches. Left unfixed, a class
name in a trait-declared accessor's docblock — `@return Attribute<Collection<int, OptionValue>,
never>` — would resolve against the *consuming* model's imports instead of the trait's, silently
degrading to `unknown` whenever the model doesn't happen to import the same class.
