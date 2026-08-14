# FormRequestRulesAnalyzer

> User-facing docs: [README § Form Requests](../../README.md#form-requests). Verified by
> [the type-inference gates](../testing/type-inference-gates.md).

`AbeTwoThree\LaravelTsPublish\Analyzers\FormRequest\FormRequestRulesAnalyzer` resolves a
FormRequest's `rules()` array into `FormRequestRuleNode`s ready for interface generation, and
composes `parent.*.child`/`parent.child` rule keys into their nearest undotted ancestor instead
of emitting them as separate flat, quoted keys.

## Trie collapse: dot-paths compose into their ancestor

`normalizeRules()` hands the raw rules to `buildRuleTrie()`, which splits every rule key on `.`
and inserts it into a trie — a `*` segment marks "array of this node", any other segment nests an
object key. A node's own rule data (its resolved TS type, required/nullable/prohibited flags,
JSDoc metadata) lives at the exact path it was declared on; intermediate ancestors created only
to reach a deeper path (e.g. `order` when only `order.id` was declared) have none.

Laravel lets an attribute contain a *literal* dot by escaping it (`'v1\.0'`), so the split is not
a bare `explode('.')`: `buildRuleTrie()` first replaces every `\.` with the `DOT_PLACEHOLDER`
sentinel (`"\x00ltsp-dot\x00"`, a byte sequence no rule key can legitimately contain), explodes on
the real separators, then restores the placeholder to `.` inside each segment. `'v1\.0'` therefore
becomes one trie node named `v1.0`, not two named `v1` and `0`.

The trie node itself is a small internal class ([`FormRequestRuleTrieNode`](../../src/Analyzers/FormRequest/FormRequestRuleTrieNode.php)),
not a plain nested array — PHPStan (this project runs level 10) rejects a directly self-referencing
`@phpstan-type` array shape as a "circular definition," so a `children` property typed
`array<string, FormRequestRuleTrieNode>` is the type-checkable way to express its unbounded depth.

`composeTrieNode()` then collapses the trie bottom-up. The branches are checked in this order,
and the order is load-bearing:

1. **A leaf carrying `required_array_keys`** (no children, non-empty `requiredArrayKeys`) has
   pseudo-children synthesized for it first and then re-enters the list below with those children in
   hand — see [`required_array_keys`](#required_array_keys-same-path-synthesized-children).
2. **A node with no children** returns its own leaf as-is — this is the existing flat behavior,
   completely unchanged for fields with no dotted continuation (`title`, `email`, `published`, …).
3. **A node whose keys are *all* explicit numeric indices** (`allKeysAreNumeric()`) composes as a
   list, not an object — `composeIndexedNode()`. See
   [numeric indices](#numeric-indices-compose-as-a-list) below.
4. **A node with a `*` child** composes as an array (`composeArrayNode()`) when `*` is its *only*
   child, or as an intersection (`composeMixedNode()`) when named siblings sit alongside it.
5. **Anything else** — named children only — composes to an inline object type
   (`composeObjectNode()`).

Because the numeric check precedes the wildcard check but demands that *every* key be numeric, a
node mixing `0` with `*` or with a named key is not "all numeric" and falls through to branch 4 or
5, where the numeric key is treated as an ordinary object key.

### Array nodes (`*` alone)

The `*` child's composed type, suffixed `[]` via `arrayWrapType()`. Required, nullable, prohibited,
and JSDoc come from the array node's **own** rule, not the element's — `roles` is required because
`'roles' => ['required', 'array', ...]` says so, not because of anything on `'roles.*'`. The
element's *own* nullability is the exception: it is not discarded, it folds into the element type
before wrapping, so `'limited_choices' => ['nullable', 'array'], 'limited_choices.*' => ['nullable', 'string']`
composes to `limited_choices?: (string | null)[] | null` — the inner `| null` is the element's, the
outer one the array's, appended later by the Blade template from the node's own `isNullable`.

A **prohibited** element short-circuits the wrap entirely: `'empties.*' => ['prohibited']` yields
`never[]`, i.e. "an array that may not contain anything", rather than an array of the element's
nominal type.

`arrayWrapType()` parenthesizes before suffixing whenever `hasTopLevelUnion()` says so —
`('a' | 'b')[]`, not the ambiguous `'a' | 'b'[]`, which TypeScript parses as `'a' | ('b'[])`.
`hasTopLevelUnion()` is depth- and quote-aware: it tracks `{}`, `<>`, `()` and `[]` nesting and
reports only a `|` at depth zero, and it skips over single-quoted string literals whole, so a
bracket or pipe character *inside* a literal (`in:>a,b` → `'>a' | 'b'`) is read as data rather than
as structure. A `|` nested inside a `{ ... }` shape therefore never triggers the parens — `[]` on
an object shape is unambiguous even when a property inside it is a union.

### Object nodes (named children)

One part per child, `{$key}{$optional}: {$type}`, joined `'; '` and wrapped `'{ ... }'` — the same
`LaravelTsPublish::validJsObjectKey()` + optional-`?` convention
`ResourceAstAnalyzer::analyzeInlineArray()` already uses for its own inline object shapes (the
`'{ '.implode('; ', $parts).' }'` wrapping itself is shared even more widely, e.g.
`arrayableShapeType()`, though that method doesn't mark individual keys optional).

A **prohibited** child is dropped from the object entirely; it can never legally appear in the
payload. When *every* named child is prohibited, no parts survive and the node composes to
`Record<string, never>` — "an object with no permitted keys" — instead of an empty `{}` or a
permissive `Record<string, unknown>`. (A prohibited *top-level* field is dropped one layer later,
by `form-request.blade.php`, which skips any field whose `isProhibited` is set.)

### Mixed nodes (`*` beside named children)

`'options' => ['array'], 'options.*' => ['string'], 'options.default' => ['string']` is Laravel's
way of describing a map whose values all share a rule and some of whose keys are pinned.
`composeMixedNode()` composes the named children as an object, composes the `*` child as the
element type (folding its nullability in the same way an array node does), and emits the
intersection:

```typescript
options?: { default?: string } & Record<string, string>;
```

An intersection rather than a `"*"` pseudo-key, and rather than a single index signature: TypeScript
rejects an index signature whose named siblings have a different type, and the intersection stays
valid even when the two halves disagree. Everything except `tsType` — required, nullable,
prohibited, JSDoc — is inherited from the object composition, i.e. from the node's own rule.

### Numeric indices compose as a list

`'items.0.name' => ['required', 'string']` describes element 0 of a list. Composing it as an object
would give `{ "0": { name: string } }`, a type no real JSON array is assignable to. When
`allKeysAreNumeric()` holds, `composeIndexedNode()` composes each index's shape, drops any
prohibited one, de-duplicates the results, joins the survivors with `' | '` and array-wraps that:

```php
'items'   => ['array'],
'items.0.name' => ['required', 'string'],

'variants' => ['array'],
'variants.0.name'  => ['required', 'string'],
'variants.1.email' => ['required', 'email'],
```

```typescript
items?: { name: string }[];
/** @format email variants.1.email */
variants?: ({ name: string } | { email: string })[];
```

The de-duplication is what keeps `items.0.name`/`items.1.name` from producing
`({ name: string } | { name: string })[]`, and `hasTopLevelUnion()` is what puts the parens around
the two-shape `variants` union. If every index is prohibited the node composes to `never[]`.

### Own rule plus children

A node with both an own rule and children (`'products' => ['required', 'array']` *and*
`'products.*.name' => [...]`) uses the children — the composed type is strictly more specific
than the `unknown[]`/`unknown` placeholder the own rule alone would give. The own rule still
supplies required/nullable/prohibited and its own JSDoc.

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
/** @format uuid order.id */
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

## JSDoc hoisting: a nested annotation still reaches the reader

Composing `order.id` into `order` puts its type inside an opaque type string, and an inline object
type has nowhere to hang a `/** @format uuid */` comment. Rather than lose the annotation,
`normalizeRules()` calls `collectChildJsDoc()` on each top-level node's children and appends the
result to that node's own `jsDocMetadata`.

`collectChildJsDoc()` walks the subtree depth-first and suffixes every collected entry with the
**full declared rule key** it came from — the accumulated path from the top-level field down,
wildcard segments included verbatim:

```php
'order.id'                   => ['required', 'uuid'],
'products.*.contact_email'   => ['required', 'email'],
```

```typescript
/** @format uuid order.id */
order: { ... };

/** @format email products.*.contact_email */
products: { ... }[];
```

The path is the disambiguator: several descendants can contribute `@format`, and without the
suffix the reader could not tell which nested key each one describes.

One subtree is skipped: a child whose own rule is prohibited contributes neither its own metadata
nor **any** of its descendants' — `collectChildJsDoc()` `continue`s before recursing. So
`'order.secret' => ['prohibited'], 'order.secret.token' => ['required', 'uuid']` hoists nothing.
That matches what the type says: `composeObjectNode()` already dropped `secret` from `order`'s
shape, so an `@format uuid order.secret.token` comment would document a key the interface does not
have.

## Optionality mapping: reused, not reinvented

A composed child's `?` comes from the same `isRequired($parsedRules) && ! $isSometimes($parsedRules)`
expression the flat path has always used (`buildLeafData()`) — a child rule list containing
`required` (and not `sometimes`) renders without `?`; anything else renders `key?:`. Nullable
children get `| null` appended inline before the part is assembled, mirroring what the Blade
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
appears as its own flat, quoted `FormRequestRuleNode`. Keeping both was redundant and, for
anything beyond one level, actively misleading (`"order.id"?: string;` implied a caller could
supply a literal `order.id` key on the request payload, which Laravel's dot-notation validation
never means).

What the fold carries into the parent is the child's **type, optionality, nullability** and — via
[JSDoc hoisting](#jsdoc-hoisting-a-nested-annotation-still-reaches-the-reader) — its **metadata
annotations, path-qualified**. What it deliberately discards is the child's identity as an
addressable field: after composition there is exactly one node per top-level key, and everything
below it is a substring of that node's type string. That is what makes the next section a real
constraint rather than a bug list.

Note that "top-level key" is not the same as "dot-free key". A key whose dot was escaped
(`'v1\.0'`) is a single trie node and therefore a single top-level field, emitted quoted because
`validJsObjectKey()` quotes anything that isn't a bare identifier:

```typescript
"v1.0": string;
```

## What composition cannot express

Three limits, all of them deliberate. Only the first is a true loss; the second is a placement
constraint, and the third is not really about composition at all:

- **`#[TsCasts]` keyed on a dot-notation path.** `FormRequestTransformer::applyTsCastsOverrides()`
  matches a node's `fieldPath`, and after composition the only field paths that exist are top-level
  keys. `#[TsCasts(['order.id' => 'Uuid'])]` or `['tags.*' => ...]` therefore matches nothing and is
  silently ignored — the override map is applied to the already-analyzed field list, so an unmatched
  key adds no field either. The composed parent is a single opaque type string, so there is no
  per-key override point inside it. Override the parent (`'order'`) to replace the whole shape, or
  type the rule precisely enough that no override is needed. The one dotted key that *does* match is
  an escaped one: `'v1\.0'` produces the field path `v1.0`, so `#[TsCasts(['v1.0' => ...])]` hits it.
- **Per-element JSDoc placement.** The annotation itself survives — it is hoisted onto the composed
  parent and path-qualified (`@format uuid order.id`) — but it cannot be *placed* on the nested
  property it describes, because an inline object type has nowhere to hang a comment. A parent with
  several annotated descendants therefore accumulates one comment block listing all of them. The
  exception is a prohibited child: its subtree's annotations are dropped outright, since the keys
  they describe are not in the emitted type at all.
- **Rules the analyzer cannot obtain.** Unchanged from the flat behavior, and not really a property
  of composition: `resolveRules()` actually *calls* `rules()` against a fake request and a stub user,
  so keys computed at runtime are captured like any other. What fails is a `rules()` that **throws**
  in that context — typically one reading real request or session state — which sets `isDynamic` and
  degrades the whole class to `Record<string, unknown>`. Nothing composes, because there is nothing
  to compose.
