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
base properties: 14128   head properties: 15476
PASS - no property regressed to unknown
```

Because it reads both sides out of git, it also enforces that regenerated output was **committed** — a
change that improves inference but leaves the trees stale compares against itself and reports nothing.

Every property is keyed by `(file, enclosing interface/namespace path, property name)`. The scope matters:
without it, `Invoice.status` and `InvoiceResource.status` collide, and the nested namespaces in
`laravel-ts-global.ts` collapse into one bucket. An earlier version of this script keyed on file and name
only, silently conflated ~116 pairs, and could never fail.

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
with TS2300 (`Duplicate identifier`).

TS2300 catches a different failure shape than the other two: not a token emitted *without* an import, but
two *different* imports resolving to the *same* local name. This is exactly how the MailPrice collision
manifested — two unrelated `MailPrice` models both aliased to `MailPriceMailPrice`, because the old aliasing
algorithm derived an alias from a single namespace segment and two classes happened to share both their
basename and that segment. `ImportNameRegistry` (`docs/components/import-name-registry.md`) exists to make
that impossible, and this gate is the standing check that it stays that way.

```bash
.github/scripts/unimportable-token-gate.sh          # report only
.github/scripts/unimportable-token-gate.sh 14       # fail if the count exceeds 14
```

```
TS2300/TS2304/TS2552 (duplicate identifier / cannot find name) in generated tree: 14
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

## Running both

```bash
composer test -- --passthru-php="-d memory_limit=1024M"   # regenerates the trees
python3 .github/scripts/unknown-regression-gate.py
.github/scripts/unimportable-token-gate.sh 14
```

Run the suite first — both gates read the committed trees, so they check whatever the last test run wrote.

The `--passthru-php` flag is needed when the local `php.ini` caps memory below ~512M; paratest spawns
workers that re-read `php.ini`, so `php -d` on the parent process does not reach them.

## What the gates do not cover

- **Fixture coverage.** They read the **committed workbench corpus**, so they only see failures the
  fixtures actually produce. A defect reachable only by a shape no fixture exercises passes both. When
  adding an inference path, add a fixture for the hazardous shape too — several real defects were found
  only by constructing a fixture and regenerating, never by reading the code or running the suite.

- **Removed properties are structurally invisible to `unknown-regression-gate.py`.** The comparison loop
  is `[... for k in h if k in b and ...]` — it only ever looks at keys present in the **head** snapshot,
  then checks whether that same `(file, scope, property)` key existed in the base snapshot. A property
  that existed at `BASE_REV` and is simply **gone** at `HEAD` never appears in `h`, so it never enters the
  loop at all — not as a pass, not as a fail, not as any kind of signal. The gate has no code path that
  even notices a key vanished.

  This is not theoretical: this branch shipped the first two deliberate property removals the gate has
  ever seen. Task 13's `$hidden`-exclusion change dropped `user.password` and `user.remember_token` from
  the generated model interface (matching Laravel's own `toArray()`/`toJson()` serialization — see
  [What gets published](../../README.md#what-gets-published-hidden-attributes-write-only-accessors)), and
  the write-only-accessor waterfall dropped `order.search_index` (a set-only mutator with no getter, no
  docblock generic, and no backing column, so it's correctly omitted rather than emitted as `unknown`).
  Both regenerated trees were reviewed by hand — reading the diff and confirming each property disappeared
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
