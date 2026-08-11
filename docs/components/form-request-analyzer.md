# FormRequestRulesAnalyzer

`AbeTwoThree\LaravelTsPublish\Analyzers\FormRequest\FormRequestRulesAnalyzer` resolves a
FormRequest's `rules()` array into `FormRequestRuleNode`s ready for interface generation, and
composes `parent.*.child`/`parent.child` rule keys into their nearest undotted ancestor instead
of emitting them as separate flat, quoted keys.

## Trie collapse: dot-paths compose into their ancestor

`normalizeRules()` splits every rule key on `.` and inserts it into a trie
(`buildRuleTrie()`) — a `*` segment marks "array of this node", any other segment nests an
object key. A node's own rule data (its resolved TS type, required/nullable/prohibited flags,
JSDoc metadata) lives at the exact path it was declared on; intermediate ancestors created only
to reach a deeper path (e.g. `order` when only `order.id` was declared) have none.

The trie node itself (`FormRequestRuleTrieNode`) is a small internal class defined in the same
file, not a plain nested array — PHPStan (this project runs level 10) rejects a directly
self-referencing `@phpstan-type` array shape as a "circular definition," so a `children` property
typed `array<string, self>` is the type-checkable way to express its unbounded depth.

`composeTrieNode()` then collapses the trie bottom-up:

- **A node with no children** returns its own leaf as-is — this is the existing flat behavior,
  completely unchanged for fields with no dotted continuation (`title`, `email`, `published`, …).
- **A node whose only child is `*`** composes to an array: the `*` child's own composed type,
  suffixed `[]` via `arrayWrapType()` (which parenthesizes a union first — `('a' | 'b')[]`, not
  the ambiguous `'a' | 'b'[]` — but leaves a bare `{ ... }` object literal unwrapped, since `[]`
  on an object shape is never ambiguous even when a property inside it is itself a union).
  Required/nullable/prohibited/JSDoc come from the array node's **own** rule, not the element's —
  `roles` is required because `'roles' => ['required', 'array', ...]` says so, not because of
  anything on `'roles.*'`.
- **A node with named children** composes to an inline object type, one part per child:
  `{$key}{$optional}: {$type}`, joined `'; '` and wrapped `'{ ... }'` — the same
  `LaravelTsPublish::validJsObjectKey()` + `{ k: T; k2?: T2 }` convention `analyzeInlineArray()`
  and `arrayableShapeType()` already use elsewhere for inline shapes. A prohibited child is
  dropped from the object entirely; it can never legally appear in the payload.
- **A node with both an own rule and children** (`'products' => ['required', 'array']` *and*
  `'products.*.name' => [...]`) uses the children — the composed type is strictly more specific
  than the `unknown[]`/`unknown` placeholder the own rule alone would give.

```php
'products'              => ['required', 'array'],
'products.*.name'       => ['required', 'string'],
'products.*.price'      => ['required', 'decimal:2'],
'order'                 => ['required', 'array'],
'order.id'              => ['required', 'uuid'],
'order.items'           => ['required', 'array'],
'order.items.*.product_id' => ['required', 'integer'],
'order.items.*.quantity'   => ['required', 'integer', 'min:1'],
```

resolves to:

```typescript
products: { name: string; price: number }[];
order: { id: string; items: { product_id: number; quantity: number }[] };
```

Recursion means the depth is unbounded — `order.items.*.quantity` is two `.*`/`.` hops deep, and
composes exactly the same way a single `tags.*` hop always has.

## Every dotted key composes — including the one-level case

Composition is a single code path with no special-casing for depth. A key with no dot never
enters a trie branch deeper than the root, so it round-trips unchanged. A key ending in a bare
`.*` (`roles.*`, `tags.*`) is a one-segment-deep case of the same array-node rule described
above — `roles: string[]` collapses identically to how it did before this composition existed,
the only difference being that the flat `"roles.*"` key is no longer also emitted alongside it
(see below).

## Optionality mapping: reused, not reinvented

A composed child's `?` comes from the same `isRequired($parsedRules) && ! $isSometimes($parsedRules)`
expression the flat path has always used (`buildLeafData()`) — a child rule list containing
`required` (and not `sometimes`) renders without `?`; anything else renders `key?:`. Nullable
children get `| null` appended inline (`childType .= ' | null'`), mirroring what the Blade
template already does for a top-level field's `isNullable` flag. The old "dotted paths are
never required" override is gone: it existed only because dotted keys used to survive as
pseudo-top-level fields you could never actually supply. Now that they compose into a real
nested property, their own `required` rule is exactly what should decide their optionality.

## `required_array_keys`: same path, synthesized children

`required_array_keys:read,write` on a leaf array with **no** other declared children
(`syntheticRequiredArrayKeyChildren()`) synthesizes pseudo-children — one per key, typed
`unknown`, always required — so `composeTrieNode()` takes the same object-node branch as a real
nested rule set:

```php
'permissions' => ['required', 'array', 'required_array_keys:read,write'],
```

resolves to `permissions: { read: unknown; write: unknown };` — the keys are known even though
their individual types aren't, which is still strictly more useful than `unknown[]`. A parent
that also has real declared children (a `*` or named continuation) skips this entirely; the real
children win.

## Flat quoted keys are dropped once they compose

A key that folds into a parent (`products.*.name`, `order.id`, `tags.*`, …) no longer also
appears as its own flat, quoted `FormRequestRuleNode` — the information lives once, in the
composed parent's type string. Keeping both was redundant and, for anything beyond one level,
actively misleading (`"order.id"?: string;` implied a caller could supply a literal
`order.id` key on the request payload, which Laravel's dot-notation validation never means).
