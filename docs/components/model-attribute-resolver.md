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
(and reused by `Concerns\ResolvesAccessorType`'s accessor-closure-vs-docblock check, which
follows the same signature-then-docblock shape) — treats a type as vague when it contains the
literal substring `unknown` (covers `unknown`, `unknown[]`, `unknown[] | Record<string,
unknown>`, `Record<string, unknown>`, …) or is exactly `object`. Anything else — `string`,
`OrderItem[]`, `{ value: number; label: string }` — is specific enough to win immediately.

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
