# Type inference gates

Two scripts in `.github/scripts/` guard the generated TypeScript against the two ways type inference
fails silently. Both run in CI (`.github/workflows/run-tests.yml`, job `type-inference-gates`) and both
can be run locally.

They exist because **a green test suite does not prove the generated types are right.** A unit test can
pass against an inner helper while the pipeline still emits a wrong type, and a fixture can pass while
emitting TypeScript that does not compile. These gates check the committed output itself.

| Script                       | Catches                                                                      |
| ---------------------------- | ---------------------------------------------------------------------------- |
| `unknown-regression-gate.py` | A property that had a real type now emits `unknown`                          |
| `unimportable-token-gate.sh` | A type token emitted without its `import` — TypeScript that will not compile |

The two are complements, not overlaps. A leaked token is a *new* property carrying a plausible-looking
type, not an existing property degrading, so the regression gate structurally cannot see it.

## `unknown-regression-gate.py`

Compares the committed type trees under `workbench/resources/js/types/data` at two revisions and fails if
any property went from a real type to one containing `unknown`.

```bash
python3 .github/scripts/unknown-regression-gate.py [BASE_REV] [HEAD_REV]
```

`BASE_REV` defaults to the branch point of the type-inference work; `HEAD_REV` defaults to `HEAD`.

```
base properties: 16452   head properties: 21096
PASS - no property regressed to unknown
```

Because it reads both sides out of git, it also enforces that regenerated output was **committed** — a
change that improves inference but leaves the trees stale compares against itself and reports nothing.

Every property is keyed by `(file, enclosing interface/namespace path, property name)`. The scope matters:
without it, `Invoice.status` and `InvoiceResource.status` collide, and the nested namespaces in
`laravel-ts-global.ts` collapse into one bucket. An earlier version of this script keyed on file and name
only, silently conflated ~116 pairs, and could never fail.

### Aliases and inline-object members

Single-line `export type X = …;` aliases are parsed the same way as properties, keyed by their name —
including a namespaced alias nested inside `declare global { namespace … { type X = …; } }`. When a
property's or alias's value contains one or more inline `{ … }` object types, each member becomes its own
key: `prop.member` for a single object, `prop[i].member` per arm for a union of objects. A member regressing
to `unknown` is no longer masked by an already-`unknown` sibling in the same object.

`prop[i]` is a positional index into the arms as written, not a content hash: reordering two union arms in a
regenerated file shifts which arm sits at index `0` and can mask a regression. This is a known, accepted
limit — not something a future change to this gate should try to fix with content-hashed arm keys.

### Parser self-test

```bash
python3 .github/scripts/unknown-regression-gate.py --parsetest
```

There is no git range that exercises a member-level `FAIL` — proven across all 143 commits that have
touched the generated tree — so this is the only guard on the alias- and member-splitting logic above. It
checks six cases lifted from the corpus: a single-line type alias, a namespaced alias inside `declare
global`, an inline object with one member, one with several members, a union of two inline objects, and a
synthetic case — a member regressing to `unknown` beside a sibling that is already `unknown[]`, the exact
shape the whole-string `FAIL` test below used to miss. Run it after any change to this script.

### Self-test

The gate has a known-bad range built in. Use it whenever you change the script:

```bash
python3 .github/scripts/unknown-regression-gate.py 0faf1f5 3880323 --selftest
```

That range deliberately turned `TrackingEvent.status` from `string` into `unknown`, so the script must
report **16 regressions** (four template trees × the model and its resource, plus the global files).
`--selftest` inverts the exit code: finding the regressions is success. A `PASS` here means the script is
broken, not the code.

### When it fails

A property degraded. Do not restore the old value and do not accept `unknown` — find the real type. The
old value may itself have been wrong: when `TrackingEvent.status` first degraded, the previous `string`
turned out to be a lie masking a broken cast (the model imported an enum's *TypeScript* alias instead of
its PHP class, and the namespace segment `\Enums\` was substring-matching the `enum` map key). The correct
answer was neither `string` nor `unknown` but `ShipmentStatusType`.

## `unimportable-token-gate.sh`

Runs `npx tsc --noEmit` over the generated tree and counts "cannot find name" diagnostics — TS2304, plus
TS2552 (`Did you mean…`), which TypeScript emits instead when a similarly-named global exists — together
with TS2300 (`Duplicate identifier`) and TS2344 (`does not satisfy the constraint`).

TS2300 catches a different failure shape than the other two: not a token emitted *without* an import, but
two *different* imports resolving to the *same* local name. This is exactly how the MailPrice collision
manifested — two unrelated `MailPrice` models both aliased to `MailPriceMailPrice`, because the old aliasing
algorithm derived an alias from a single namespace segment and two classes happened to share both their
basename and that segment. `ImportNameRegistry` (`docs/components/import-name-registry.md`) exists to make
that impossible, and this gate is the standing check that it stays that way.

TS2344 is a third shape: the token is imported and unique, but named somewhere its own type rejects it —
the case that motivated adding it was `Pick<Model, K>` built from raw schema columns while the model
interface omits `$hidden` ones, so `K extends keyof T` failed. See
`docs/components/resource-ast-analyzer.md`.

```bash
.github/scripts/unimportable-token-gate.sh          # report only
.github/scripts/unimportable-token-gate.sh 14       # fail if the count exceeds 14
```

```
TS2300/TS2304/TS2344/TS2552 (duplicate identifier / cannot find name / bad type argument) in generated tree: 14
   8   CustomObject
   2   ExtendableInterface
   2   Coordinate
   1   PostAttributes
   1   AddressResource
PASS - no new unimportable or colliding tokens (baseline 14)
```

### The baseline

The baseline is **14**, not zero. Those names come from `custom_ts_mappings` and `#[TsType]` — the escape
hatches where the *consuming app* declares the type, so the package cannot emit an import for them. They
are expected and must not be "fixed".

Raise the baseline only when you add a fixture that legitimately uses one of those escape hatches, and say
so in the commit message. A rising baseline for any other reason is the bug this gate exists to catch.

### Fails closed

If `tsc` cannot run at all — bad `tsconfig.json`, missing binary, an `include` matching nothing — it emits
no TS2304 lines, which a naive grep reads as a pass. The script distinguishes setup errors (printed
unanchored) from real diagnostics (always prefixed `file(line,col):`) and fails on the former.

One residual limit: it still passes if `tsc` checks *fewer* files than intended while checking something.

### When it fails

A type token reached the output without its import. The generated TypeScript will not compile. The fix is
never to add the import by hand — the type resolution that produced the token must either carry the FQCN
through to the import machinery, or degrade to `unknown`.

This failure mode recurred nine times during the type-inference work, and every instance had the same
shape: a `return` that fired before a guard. If you hit it, prefer restructuring the accept/reject decision
into a single check over all fields rather than reordering two branches.

## The `@tolki/ts` patch and `tests/types/tolki-assertions.ts`

`@tolki/ts` ships two declaration files whose first line imports from
`'../packages/types/src/index.ts'` — a monorepo source path that does not exist inside the published
tarball. `dist/enums.d.ts` and `dist/routes.d.ts` both have it, and `dist/index.d.ts` is only
`export * from './enums'; export * from './routes';`, so the package's **entire** type surface flows
through one of the two broken imports.

**Versions known to carry the defect: `0.2.0` and `1.0.1`.** The `1.0.1` major bump did not fix it, and
the two `dist` files are byte-identical between the two releases — so the patch regenerated against
`1.0.1` is identical to the original `0.2.0` one. Before assuming a newer release is fixed, check the
**pristine tarball**, never `node_modules`:

```bash
npm pack @tolki/ts@<version> && tar xzf tolki-ts-<version>.tgz && head -1 package/dist/enums.d.ts
```

Reading `node_modules` after any install is misleading: `postinstall` has already run `patch-package`, so
a successfully patched file is indistinguishable from a genuine upstream fix.

`skipLibCheck: true` suppresses the resulting TS2307s, and the failure is then completely silent:
every exported type degrades to `any`. `AsEnum<typeof Status>` accepted `.totallyBogusProperty` and was
assignable to `string`; `defineEnum`'s result was `any`, so `Status.Draft`, `.from()`, `.tryFrom()` and
`.cases()` were all unchecked. Nothing in the suite or either gate noticed.

Two fixes do **not** work:

| Candidate | Outcome |
| --- | --- |
| `skipLibCheck: false` | Surfaces the two TS2307s but does not repair the type — still `any` |
| `paths` mapping in `tsconfig.json` | Never consulted: TypeScript applies `paths` only to *non-relative* specifiers, and `../packages/types/src/index.ts` is relative |

The repair is `patches/@tolki+ts+1.0.1.patch`, applied by `patch-package` from the `postinstall` hook.
It rewrites both specifiers to the bare name `@tolki/types`, which is why `@tolki/types` is a **direct**
dependency rather than only a transitive one — the bare specifier must be guaranteed to resolve.
**Delete the patch, the `postinstall` hook and the direct dependency once a fixed `@tolki/ts` ships**;
the real fix belongs in that package's dts emitter.

The patch filename encodes the version it was generated against. When `@tolki/ts` is upgraded,
`patch-package` warns of a version mismatch on install and keeps applying the old patch as long as its
context lines still match. Regenerate with `npx patch-package @tolki/ts` and delete the stale file, so
the filename never misstates what is actually installed.

`tests/types/tolki-assertions.ts` is the permanent regression guard and must stay inside `tsconfig.json`'s
`include`. Note how it is written: an `IsAny<T>` conditional **cannot** work here. When an import fails to
resolve, TypeScript propagates its *error type* through the conditional and suppresses the cascading
diagnostic, so `IsAny<AsEnum<…>>` yields the error type rather than `true` and the assertion silently
passes in exactly the broken state it is meant to catch. The guard instead uses `@ts-expect-error` on a
deliberate constraint violation (`AsEnum<string>`, `RouteCallResult<number>`): when the types are real
those raise TS2344 and the directive is satisfied, and when they have degraded to `any` no error occurs
and TypeScript reports **TS2578 "Unused '@ts-expect-error' directive"** — which is emitted by the
directive machinery and therefore survives `any`-poisoning.

`unimportable-token-gate.sh` counts only TS2300/TS2304/TS2344/TS2552, so it does **not** fail on TS2578.
CI evaluates this guard in its own step (`Gate - the @tolki/ts type surface resolves`), which fails on any
diagnostic under `tests/types/`. Locally:

```bash
npx tsc --noEmit -p tsconfig.json 2>&1 | grep "^tests/types/"   # must print nothing
```

## Running both

```bash
composer test -- --passthru-php="-d memory_limit=1024M"   # regenerates the trees
python3 .github/scripts/unknown-regression-gate.py
.github/scripts/unimportable-token-gate.sh 14
```

Run the suite first — both gates read the committed trees, so they check whatever the last test run wrote.

The `--passthru-php` flag is needed when the local `php.ini` caps memory below ~512M; paratest spawns
workers that re-read `php.ini`, so `php -d` on the parent process does not reach them.

When changing `unknown-regression-gate.py` itself, also run its
[parser self-test](#parser-self-test): `python3 .github/scripts/unknown-regression-gate.py --parsetest`.

## What the gates do not cover

- **Fixture coverage.** They read the **committed workbench corpus**, so they only see failures the
  fixtures actually produce. A defect reachable only by a shape no fixture exercises passes both. When
  adding an inference path, add a fixture for the hazardous shape too — several real defects were found
  only by constructing a fixture and regenerating, never by reading the code or running the suite.

- **TS2307 (`Cannot find module`) is counted by neither gate.** `unimportable-token-gate.sh` greps only
  TS2300/TS2304/TS2344/TS2552, and an unresolved *module* is not an existing property degrading to
  `unknown`, so the regression gate is structurally blind to it too. That diagnostic is the signature of
  an import of a class the package never writes a file for — the failure mode `PublishedResourceRegistry`
  exists to prevent, documented under
  [convention guesses are gated on the published set](../components/resource-ast-analyzer.md#toresource-convention-guesses-are-gated-on-the-published-set).

  A blanket TS2307 gate is impractical. `npx tsc --noEmit -p tsconfig.json` currently reports **59** of
  them, and 58 are bare aliases — `@/types/audit`, `@js/types/settings`, `@workbench/types` and friends —
  from app-side `custom_ts_mappings` and `#[TsType]`. Those are the same escape hatches behind the TS2304
  baseline: the consuming app declares the module, so the package cannot emit anything that resolves.
  Re-measure before quoting these; the count moves whenever a fixture's imports change. It dropped from
  60 when `4016f7c9` (`Make relation except() expansions return columns only`) stopped
  `warehouse-resource.ts` importing `@js/types/settings`.

  The open option is a **sub-gate scoped to relative specifiers only**. A `./`- or `../`-relative import
  inside the generated tree resolves against files this package itself writes, so it is never an app-side
  escape hatch. Exactly one exists today — `default-example/app/models/warehouse.ts` importing
  `'../value-objects'` for the unpublished `Workbench\App\ValueObjects\Coordinate`, which is also two of
  the **12** TS2304s — so the sub-gate would need either that instance fixed or a baseline of 1. (The
  gate's baseline of 14 is not the TS2304 count: it is the combined TS2300/TS2304/TS2344/TS2552 total,
  currently 0 + 12 + 0 + 2.) Real, scoped work, deliberately left as a follow-up.

- **Removed properties are structurally invisible to `unknown-regression-gate.py`.** The comparison loop
  is `[... for k in h if k in b and ...]` — it only ever looks at keys present in the **head** snapshot,
  then checks whether that same `(file, scope, property)` key existed in the base snapshot. A property
  that existed at `BASE_REV` and is simply **gone** at `HEAD` never appears in `h`, so it never enters the
  loop at all — not as a pass, not as a fail, not as any kind of signal. The gate has no code path that
  even notices a key vanished.

  This is not theoretical: this branch shipped the first base-only keys the gate has ever seen, both from
  the write-only-accessor waterfall (`cb7c302`). `order.search_index` was dropped outright — a set-only
  mutator with no getter, no docblock generic, and no backing column, so it is correctly omitted rather
  than emitted as `unknown`. `profile.normalized_phone` was *moved*: in the three split-template trees it
  left `ProfileMutators` and reappeared in `Profile`, where the same-named column types it
  `string | null` instead of `unknown`. Because keys are scoped by enclosing interface, a relocation is a
  removal plus an addition, and the gate is blind to exactly the half that would tell you a property left
  its old home. (In `full-template-example` the same change is fully visible — one interface holds both
  sections, so the key never moved and the gate simply saw `unknown` become `string | null`. Whether a
  change is observable can depend on the template, which is its own reason not to treat a green gate as
  coverage.)

  What did **not** ship is a `$hidden` removal, and it is worth being precise about that, because it is
  the change most likely to be misremembered as one. `config/ts-publish.php` ships
  `'exclude_hidden' => false`, so hidden attributes are published by default: `user.password` and
  `user.remember_token` are both still present, in all four committed trees. The setting is the opt-in
  that *would* remove them — turn it on and those two properties leave `User` on the next regeneration,
  matching Laravel's own `toArray()`/`toJson()` serialization (see
  [What gets published](https://tolki.abe.dev/ts/models.html#what-gets-published-hidden-attributes-write-only-accessors)).
  No workbench example enables it, so the committed corpus exercises the permissive branch only and the
  gate has never actually been shown a `$hidden`-driven removal — a second reason not to lean on it here.

  Every regenerated tree was reviewed by hand — reading the diff and confirming each property disappeared
  for the intended reason — because the gate could not do, and did not do, any part of that verification.
  A property quietly dropping for the *wrong* reason (a bug, not a deliberate design choice) would pass
  both gates exactly the same way these did.

  **Practical consequence:** whenever a change might remove a property — excluding more columns, widening
  `$hidden`, tightening an accessor's visibility, deleting or renaming a workbench fixture — diff the
  regenerated `workbench/resources/js/types/data` tree by hand (`git diff` on the committed output) and
  account for every property that disappears. Neither gate substitutes for that review. Teaching the gate
  to see removals — a second pass keyed the opposite direction (`for k in b if k not in h`), reporting each
  base-only key as a `REMOVED — verify intentional` line rather than an automatic failure, since removal is
  often correct — is real, scoped work, deliberately left for a follow-up rather than folded into this
  documentation-only task.
