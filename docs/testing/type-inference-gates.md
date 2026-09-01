# Type inference gates

Two scripts in `.github/scripts/` guard the generated TypeScript against the two ways type inference
fails silently. Both run in CI (`.github/workflows/run-tests.yml`, job `type-inference-gates`) and both
can be run locally.

They exist because **a green test suite does not prove the generated types are right.** A unit test can
pass against an inner helper while the pipeline still emits a wrong type, and a fixture can pass while
emitting TypeScript that does not compile. These gates check the committed output itself.

Types are gated here; speed is gated separately — see
[`performance-gate.md`](performance-gate.md) for the publish-speed A/B gate.

A third, non-blocking CI job — [`upstream-drift`](#the-upstream-drift-job) — runs alongside these two. It is
not a script under `.github/scripts/` and it does not gate a merge; it watches for a different kind of
silent failure, upstream API drift in two frozen dependencies, and is documented separately below.

| Script                       | Catches                                                                      |
| ---------------------------- | ---------------------------------------------------------------------------- |
| `unknown-regression-gate.py` | A property that had a real type now emits `unknown`                          |
| `unimportable-token-gate.sh` | A type token emitted without its `import` — TypeScript that will not compile |

The two are complements, not overlaps. A leaked token is a *new* property carrying a plausible-looking
type, not an existing property degrading, so the regression gate structurally cannot see it.

## `unknown-regression-gate.py`

Compares the committed type trees under `workbench/resources/js/types/data` at two revisions and fails if
a property that carried a real type now emits one containing `unknown`. One blind spot survives by
design: an inline object that *already* carries an `unknown` member anywhere cannot report its own
wholesale collapse. See [What the gates do not cover](#what-the-gates-do-not-cover).

```bash
python3 .github/scripts/unknown-regression-gate.py [BASE_REV] [HEAD_REV]
```

`BASE_REV` defaults to the branch point of the type-inference work; `HEAD_REV` defaults to `HEAD`.

```
base properties: 17072   head properties: <moves with every regeneration>
PASS - no property regressed to unknown
```

The base count is stable, because `BASE_REV` is a pinned commit and only a change to this script's own
parser moves it. The head count changes whenever the trees are regenerated, so it is left as a
placeholder rather than a literal that rots on every commit. Neither number is load-bearing; the
`PASS`/`FAIL` line is.

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
key *in addition to* the parent: `prop.member` for a single object, `prop[i].member` per arm for a union of
objects, alongside `prop` itself holding the whole rendered value. A member regressing to `unknown` is
therefore not masked by an already-`unknown` sibling in the same object, and a property whose whole inline
object collapses to a bare `unknown` still matches its own base key rather than sharing none — provided
that object was entirely real-typed at base. If it was not, see the residual below.

Keeping the parent is what makes the whole-object case visible at all. When only the members were keyed,
a base holding `heading_content.title` and `heading_content.summary` and a head holding just
`heading_content: unknown` had no key in common, and `detect_regressions()` — which iterates
`for k in h if k in b` — matched nothing and reported zero regressions.

The parent key holds the whole rendered value, so it is coarse on purpose: it also fires when an existing
object merely *gains* a member typed `unknown`, since that member is a new key with no base counterpart
and nothing else in the gate would see it. Treat such a failure as a real question to answer, not as
noise to silence.

`prop[i]` is a positional index into the arms as written, not a content hash: reordering two union arms in a
regenerated file shifts which arm sits at index `0` and can mask a regression. This is a known, accepted
limit — not something a future change to this gate should try to fix with content-hashed arm keys.

A `Pick<Model, K>` reference is the companion case: it has no `{` for the member-splitting logic to
descend into, so it stays a single opaque key rather than expanding into `prop.member`/`prop[i].member`
entries. This is de-duplicated coverage, not lost coverage — the members `Pick<>` names still live in
`Model`'s own generated file (e.g. `app/models/user.ts`), which this same gate already watches, so a
regression on one of them still fails here, just keyed under the model file instead of the resource
that references it.

### Parser self-test

```bash
python3 .github/scripts/unknown-regression-gate.py --parsetest
```

No commit in this repo's history exercises a member-level `FAIL`, so this self-test is the only guard on
the alias-, parent- and member-splitting logic above. Measured, not assumed: 190 commits have touched the
generated tree (`git rev-list --count HEAD -- workbench/resources/js/types/data`), and replaying each of
the 185 parent→commit transitions that changed a `.ts` file through `parse_source()` and
`detect_regressions()` reports a regression on 2 of them and a **member-level** regression on **0**. That
covers consecutive transitions, not every possible `BASE..HEAD` pair, so it is a strong absence rather
than a proof. It checks seven cases: five lifted from the
corpus — a single-line type alias, a namespaced alias inside `declare global`, an inline object with one
member, one with several members, and a union of two inline objects — plus two synthetic regressions. The
first is a member degrading to `unknown` beside a sibling that is already `unknown[]`, the exact shape the
whole-string `FAIL` test below used to miss. The second is a whole inline object collapsing to a bare
`unknown`, the shape member-splitting itself used to miss. Run it after any change to this script.

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
.github/scripts/unimportable-token-gate.sh              # report only
.github/scripts/unimportable-token-gate.sh 10           # fail if the count exceeds 10
.github/scripts/unimportable-token-gate.sh 10 0 61      # also gate both TS2307 sub-counts
```

```
TS2300/TS2304/TS2344/TS2552 (duplicate identifier / cannot find name / bad type argument) in generated tree: 10
   8   CustomObject
   2   ExtendableInterface
TS2307 (cannot find module) with a relative specifier in generated tree: 0
TS2307 (cannot find module) with a bare specifier in generated tree: 61
  @/types/geo
  @js/types/settings
  ... (61 lines total, one per diagnostic — this is the raw grep output, not a deduplicated
  summary; see "The bare-specifier baseline" below for the full list and its 21-name breakdown)

PASS - no new unimportable or colliding tokens (baseline 10)
PASS - no new relative-specifier TS2307s (baseline 0)
PASS - no new bare-specifier TS2307s (baseline 61)
```

### The baseline

The baseline is **10**, not zero, and it is **not** made of `custom_ts_mappings` entries. The workbench's
`custom_ts_mappings` is empty (`workbench/config/ts-publish.php:80-82` holds only a commented-out example),
so it contributes none of the 10. Traced to source, the two surviving names are:

| Name | Count | Where it comes from | Expected? |
| --- | --- | --- | --- |
| `CustomObject` | 8 | A `@return array{…, custom_val: CustomObject, …}` docblock shape in `workbench/app/Http/Resources/Concerns/IncludesExtras.php`. A resource docblock shape map is string-only, so it carries no FQCN and no import can be derived. | Yes — app-declared |
| `ExtendableInterface` | 2 | `#[TsExtends('ExtendableInterface')]` on `workbench/app/Http/Resources/Concerns/ExtendsInterfaces.php`, with no import argument. (Its sibling `#[TsExtends(…, '@/types/util', …)]` on the next line passes one and resolves fine.) | Yes — app-declared |

Both names are genuine escape hatches, in two different flavors, neither of them `custom_ts_mappings`: the
*consuming app* declares the type and the package has no FQCN or import path to work from. They are expected
and must not be "fixed". As of this measurement the bucket holds nothing else — but it has held real defects
before, so re-derive rather than assume; the two most recent are recorded below.

`PostAttributes` sat in this bucket until the count dropped from 12 to 11.
`GlobalsWriter` built its `$externalTypeImports` map (`src/Writers/GlobalsWriter.php`, the block beginning
at the comment "Collect external (non-relative) type imports") from three generator collections —
`modelGenerators`' `customImports`, `resourceGenerators`' non-relative `typeImports`, and
`broadcastEventGenerators`' non-relative `typeImports` — with `formRequestGenerators` absent, so
`UpdatePostRequest`'s `#[TsCasts(['attributes' => ['type' => 'PostAttributes', 'import' => '@js/types/posts']])]`
reached the modular flavor and was dropped on the way to the global one. A fourth loop over
`formRequestGenerators` closed that. (`enumGenerators` is still absent from that map, but `EnumTransformer`
declares no import channel at all, so that is not a gap.)

The measured effect was a move, not a removal, as the mechanism implied: `@js/types/posts` is app-declared
either way, so the *name* now resolves and the unresolved *module* joined the uncounted TS2307 bucket. That
bucket rose by three rather than one, because the same loop also propagated two form-request `#[TsExtends]`
imports the globals body never references. `laravel-ts-global.ts` now carries **20** imports, at lines 9-28,
six `@js/types/*` and fourteen `@/types/*`.

`AddressResource` was a real leak rather than an escape hatch, and took the count from 11 to 10 when it
was fixed. `AddressResource` carries `#[TsResource(name: 'Address')]`, so it **is**
published — as the interface `Address` in `app/http/resources/address.ts` — but `InlineArrayFqcnResource`'s
`AddressResource::make($this->user)` reference emitted the *class basename*, which nothing declares. It was
the corpus's only live instance of the counted/uncounted split
[`resource-ast-analyzer.md`](../components/resource-ast-analyzer.md) describes, showing up once per output
flavor: `TS2552` on the bare name in `laravel-ts-global.ts`, counted here, and `TS2724` on the import in
`app/http/resources/inline-array-fqcn-resource.ts` (`'"."' has no exported member named 'AddressResource'`),
counted by neither gate. Both were reproduced before the fix and both are gone after it, which is the point
worth keeping: the gate saw one of the two, so the baseline moved by 1 while 2 diagnostics disappeared.

Resolving an analyzer-derived resource reference through the same `#[TsResource]`-aware naming the publisher
uses closed it. No live instance of that split remains in the corpus — `npx tsc --noEmit -p tsconfig.json`
over the generated tree now reports exactly three codes, **61** TS2307, **10** TS2304 and **5** TS6196, with
no TS2305 or TS2724 among them. To exercise the uncounted half again you have to synthesize one, as
[`resource-ast-analyzer.md`](../components/resource-ast-analyzer.md) does with the `#[TsExclude]`d
`AttachmentResource` fixture.

Raise the baseline only when you add a fixture that legitimately uses one of those escape hatches, and say
so in the commit message. A rising baseline for any other reason is the bug this gate exists to catch. And
do not read the current number as ten *approved* diagnostics — re-derive the origins before quoting them.
As of this measurement both names are escape hatches, but that is a measurement, not a guarantee: the two
entries above each sat in this bucket looking like one.

`10` is a current count, not a target. It was `14` until `toTsType()` gained step 5c, which inlines a plain
value object's property shape instead of emitting a class token for it; that removed the two `Coordinate`
TS2304s and, in the same change, took the relative-specifier TS2307 count below (see
[The TS2307 sub-gates](#the-ts2307-sub-gates)) from `1` to `0` — one fixture change, two different diagnostic
codes disappearing together. It fell to `11` when `GlobalsWriter` gained its form-request import loop, and to
`10` when analyzer-derived resource references started honoring `#[TsResource(name:)]`. Lowering a baseline
once the defect behind it is actually gone is the point; defending the number is not.

### The TS2307 sub-gates

Two further optional arguments gate TS2307 ("Cannot find module") diagnostics — kept as **two separate
counts with two separate baselines**, not pooled into one:

- **Relative-specifier TS2307** (second argument, `RELATIVE_BASELINE`). A relative specifier (`./` or `../`)
  can only resolve against a file *this package itself writes*, so an unresolved one is never the app-side
  `custom_ts_mappings` escape hatch behind the baseline above — it is always the failure mode
  `PublishedResourceRegistry` exists to prevent (see [below](#what-the-gates-do-not-cover)). This baseline is
  always `0`. Unlike the other two baselines in this script, it has no legitimate non-zero cause, so it is
  never appropriate to raise it — a non-zero reading is a defect to fix, not a fixture to explain away.
- **Bare-specifier TS2307** (third argument, `BARE_BASELINE`), e.g. `@js/types/settings`, `@/types/geo`. The
  token is imported and the specifier is well-formed, but the module it names lives in the *consuming app*,
  not in this package or its workbench — the same kind of app-side escape hatch as the TS2304 baseline
  above, just reached through an import rather than a bare name.

**Why two counts instead of one.** An earlier version of this gate summed the two into a single TS2307
baseline. That pools a zero-tolerance signal into a large, ordinarily-fluctuating one: the bare-specifier
count moves on routine fixture churn (see the history under
[The bare-specifier baseline](#the-bare-specifier-baseline) below — it has moved 58→59→60→61 and back down
across unrelated changes), so a commit that happens to drop one bare-alias import while separately
introducing one broken relative import nets to the same combined total and passes silently. Keeping the two
counts apart means a single new relative TS2307 fails immediately, at exactly the same combined total that
would otherwise have looked clean.

Before either sub-gate existed, both flavors were an unenumerated footnote (see
[below](#what-the-gates-do-not-cover)) — nothing stopped the annotation machinery from emitting an import to
a module that does not exist, and only a live `tsc` run over the generated tree would ever have caught it.
Each sub-gate gets its own baseline rather than joining the main one above because this is a structurally
different failure (an unresolved *module*, not an unresolved *name*) with its own, distinct set of
legitimate escape hatches.

```bash
.github/scripts/unimportable-token-gate.sh 10          # unchanged: neither TS2307 check runs
.github/scripts/unimportable-token-gate.sh 10 0        # gate the relative-specifier count only
.github/scripts/unimportable-token-gate.sh 10 0 61     # gate both TS2307 counts
```

Each argument activates its own gate on top of the ones before it; passing fewer leaves the rest report-only
— the same truncation behavior the script already had before the bare-specifier count existed.

#### The relative-specifier baseline

**0**, always. It was `1` while
`workbench/resources/js/types/data/default-example/app/models/warehouse.ts` imported `Coordinate` from
`'../value-objects'` — the unpublished `Workbench\App\ValueObjects\Coordinate`. `toTsType()` step 5c now
inlines that class's property shape, so no relative import is emitted and the count has stayed `0` since.

#### The bare-specifier baseline

**61**. It spans **21 distinct module names**, every one an app-side alias namespace the consuming app is
expected to declare: `@/types/*` (14 names, 40 diagnostics), `@js/types/*` (6 names, 20) and
`@workbench/types` (1). None are unresolved npm packages — this repo's own `@tolki/ts` and `@tolki/types`
dependencies are installed and resolve cleanly, and no diagnostic here names an npm-style package outside
this package's own alias conventions; that is what makes 61 a reasonable baseline to bake in rather than a
symptom of a missing `npm install`. Re-measure before quoting 61; it moves whenever a fixture's imports
change, the same way the TS2304 baseline above does. It dropped from 60 when `4016f7c9`
(`Make relation except() expansions return columns only`) stopped `warehouse-resource.ts` importing
`@js/types/settings`, and from 59 when step 5c stopped `warehouse.ts` importing `'../value-objects'` (the
same fixture change that took the relative-specifier count from `1` to `0` above — one fix, two different
diagnostic codes disappearing together). It rose from 58 when `GlobalsWriter` gained its form-request import
loop and `laravel-ts-global.ts` picked up three more bare specifiers. `npx tsc --noEmit -p tsconfig.json
2>&1 | grep "error TS2307" | grep -vE "Cannot find module '\.{1,2}/"` reproduces it directly.

#### Proving each gate fires

Three independent claims, each checked separately — a multi-count gate where only one branch was ever
exercised is not meaningfully better than the single-count gate it replaced.

**Main gate (TS2300/2304/2344/2552).** One below its own baseline fails immediately, before either TS2307
sub-gate runs:

```bash
.github/scripts/unimportable-token-gate.sh 9 0 61   # exit 1: "token count rose from 9 to 10"
```

**Bare-specifier gate.** One below its baseline fails, with both the main gate and the relative-specifier
gate passing first:

```bash
.github/scripts/unimportable-token-gate.sh 10 0 60   # exit 1: "bare-specifier TS2307 count rose from 60 to 61"
```

**Relative-specifier gate.** Its baseline is always `0`, so a one-below comparison isn't available — instead
prove detection directly, the same way the sub-gate's original negative control worked before the widening.

*Detection control (synthesize an offending import).* This is the one that proves the gate still *finds* an
unresolvable relative specifier, not merely that its comparison arithmetic works:

```bash
printf "import type { Nope } from './deliberately-missing';\nexport type Control = Nope;\n" > tests/types/relative-subgate-control.ts
.github/scripts/unimportable-token-gate.sh 10 0 61   # exit 1: "relative-specifier TS2307 count rose from 0 to 1"
rm tests/types/relative-subgate-control.ts
```

Delete the scratch file afterwards: `tests/types/` has its own CI step that fails on any diagnostic there.

*Comparison control (no setup).* A negative baseline makes `rel_count -gt relative_baseline` true no matter
the committed count, so this fails against the committed tree with nothing to clean up:

```bash
.github/scripts/unimportable-token-gate.sh 10 -1 61   # exit 1: "relative-specifier TS2307 count rose from -1 to 0"
```

It only exercises the threshold branch — the grep feeding it is not under test — so it is a weaker check
than the synthesized import, not a substitute for it.

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

## The `upstream-drift` job

`.github/workflows/run-tests.yml`'s `upstream-drift` job runs after `type-inference-gates` on every push.
It installs `laravel/surveyor:'*'` and `laravel/ranger:'*'` — their latest tags, unpinned from
`composer.json`'s frozen `^0.1` carets — then runs `composer analyse` and five test files. Three of them
still exercise the Surveyor-backed pipeline; the two broadcast-event files no longer touch Surveyor since
that feature went native, and stay listed as drift cover until the exit removes both packages:

```text
tests/Unit/Transformers/BroadcastEventTransformerTest.php
tests/Unit/Analyzers/SurveyorTypeMapperTest.php
tests/Unit/Analyzers/Inertia/InertiaPageAnalyzerTest.php
tests/Unit/Analyzers/Inertia/InertiaSharedDataAnalyzerTest.php
tests/Unit/Writers/BroadcastEventWriterTest.php
```

**It is expected to be RED**, and that is the point, not a bug to fix. `composer.json` deliberately freezes
`laravel/surveyor` at `^0.1.10` and `laravel/ranger` at `^0.1.12` — see
[`docs/decisions/2026-08-31-surveyor-staged-exit.md`](../decisions/2026-08-31-surveyor-staged-exit.md). That
ADR exists because a measured bump to surveyor v0.3.0 / ranger v0.5.0 regressed 12 committed `.ts` files (the
two packages renamed `ClassResult` to `ClassLikeResult`, among other breaks) and the `^0.1` caret hides that
kind of drift from ordinary CI entirely. `upstream-drift` installs the unpinned latest inside its own job
only — it does not touch `composer.json` — so the drift is visible on the CI dashboard as a standing red job
instead of silently accumulating behind the caret until someone bumps it by hand.

`continue-on-error: true` keeps it non-blocking: a red `upstream-drift` never fails the workflow or blocks a
merge. Do not "fix" it by pinning its `composer require` back to the frozen versions, deleting the job, or
loosening the assertions in the five tests it runs — a green `upstream-drift` would mean upstream stopped
drifting, and the job's only job is to notice if that ever becomes true. It goes away on its own, deliberately,
once a later phase in the unified-AST-engine plan moves broadcast events, then Inertia shared data, then page
props onto the native `AstEngine` and removes both packages (`docs/decisions/2026-08-31-surveyor-staged-exit.md`'s
"Exit gates"). Until then, red is the expected, informative state.

## Running both

```bash
composer test -- --passthru-php="-d memory_limit=1024M"   # regenerates the trees
python3 .github/scripts/unknown-regression-gate.py
.github/scripts/unimportable-token-gate.sh 10 0 61
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

- **TS2307 (`Cannot find module`) is counted, in two separate baselines, not the main one.**
  `unimportable-token-gate.sh`'s main count greps only TS2300/TS2304/TS2344/TS2552, and an unresolved
  *module* is not an existing property degrading to `unknown`, so the regression gate is structurally
  blind to a bad import too. [The TS2307 sub-gates](#the-ts2307-sub-gates) above are where every TS2307 is
  counted instead, kept apart on purpose: the relative-specifier count, whose diagnostic is the signature of
  an import of a class the package never writes a file for (the failure mode `PublishedResourceRegistry`
  exists to prevent, documented under
  [convention guesses are gated on the published set](../components/resource-ast-analyzer.md#toresource-convention-guesses-are-gated-on-the-published-set),
  including its shared `InspectsAstNodes::resolveCollectedResourceClass()` resolver, which both
  `ResourceAstAnalyzer` and `InertiaPageAnalyzer` call), and the bare-specifier count, gated separately so
  that ordinary bare-alias churn can never mask a new relative-specifier regression inside a combined total.

  `npx tsc --noEmit -p tsconfig.json` currently reports **61** TS2307s, and the relative-specifier count is
  0, so all 61 are bare specifiers, and the bare-specifier baseline is set to that 61. They span **21
  distinct module names**, every one under an app-side alias namespace — `@/types/*` (14 names, 40
  diagnostics), `@js/types/*` (6 names, 20) and `@workbench/types` (1) — counted straight off the
  `Cannot find module` text. The consuming app is expected to declare those modules, so the package cannot
  emit anything that resolves. They reach the output through several different annotation channels
  (`#[TsCasts]`, `#[TsType]` and `#[TsExtends]` all take an import argument); this measurement counted the
  specifiers, not their annotations, so treat the channel breakdown as unenumerated. They are the same
  *kind* of app-side escape hatch as most of the TS2304 baseline, but not the same mechanism: here the name
  is imported and the module is undeclared, while `CustomObject` and `ExtendableInterface` carry no import
  at all. None of the 61 is an npm package this repo failed to install — `@tolki/ts` and `@tolki/types` are
  both direct dependencies and resolve cleanly — so, unlike an uninstalled-package count, this baseline will
  not silently balloon on a fresh `npm ci`. Re-measure before quoting these; the count moves whenever a
  fixture's imports change — see [The bare-specifier baseline](#the-bare-specifier-baseline) above for its
  history.

  Counting the bare half at all closed the gap this bullet used to describe, but it did not make the 61
  disappear — they are real, currently-unavoidable app-side escape hatches, not defects fixed by counting
  them. [The TS2307 sub-gates](#the-ts2307-sub-gates) above carries the full history of both baselines,
  including why they are kept as two counts instead of summed into one. (The main gate's baseline of 10 is
  not the TS2304 count, though they coincide today: it is the combined TS2300/TS2304/TS2344/TS2552 total,
  currently 0 + 10 + 0 + 0 — a separate number from either TS2307 baseline.)

- **An inline object that already contains `unknown` cannot report its own wholesale collapse.**
  `detect_regressions()` gates the base side on the substring test `"unknown" not in b[k]`, and the
  restored parent key's value is the *whole rendered object*. So one already-`unknown` member anywhere
  inside an object disarms that object's own parent key; when the object then collapses to a bare
  `unknown`, its member keys are absent from the head snapshot and nothing is left to match. This is the
  gate's founding design, not a regression — the same substring test disarmed the parent key before
  member splitting existed — but it is a real residual and the prose above is scoped to it.

  Measured at `HEAD`: **1104** top-level inline-object properties, **128** of them already carrying
  `unknown` (about 12%). At the default base rev `a6c268da`: **576** and **72**. Real exposure is the
  real-typed members sitting under those dirty parents — **120** member keys across six property names
  (`meta`, `tree_from_docblock`, `metadata`, `grid_config`, `shipping`, `grid_configs`), with at most
  **3** real members in any one property. The other eight dirty property names have no real-typed member
  at all, so nothing could be lost there. Re-derive these with a snapshot diff rather than quoting them;
  they move with every regeneration.

- **Removed properties are structurally invisible to `unknown-regression-gate.py`.** The comparison loop
  is `[... for k in h if k in b and ...]` — it only ever looks at keys present in the **head** snapshot,
  then checks whether that same `(file, scope, property)` key existed in the base snapshot. A property
  that existed at `BASE_REV` and is simply **gone** at `HEAD` never appears in `h`, so it never enters the
  loop at all — not as a pass, not as a fail, not as any kind of signal. The gate has no code path that
  even notices a key vanished.

  This is not theoretical: this branch shipped the first base-only property **roots** the gate has
  ever seen, both from the write-only-accessor waterfall (`cb7c302`). `order.search_index` was dropped
  outright — a set-only mutator with no getter, no docblock generic, and no backing column, so it is
  correctly omitted rather than emitted as `unknown`. `profile.normalized_phone` was *moved*: in the
  three split-template trees it left `ProfileMutators` and reappeared in `Profile`, where the
  same-named column types it `string | null` instead of `unknown`. Because keys are scoped by
  enclosing interface, a relocation is a removal plus an addition, and the gate is blind to exactly
  the half that would tell you a property left its old home. (In `full-template-example` the same
  change is fully visible — one interface holds both sections, so the key never moved and the gate
  simply saw `unknown` become `string | null`. Whether a change is observable can depend on the
  template, which is its own reason not to treat a green gate as coverage.)

  Keep the base-only *root* separate from the base-only *key*. Member splitting keys each inline object's
  members individually, so any property that changes shape strands its old member keys on the base side.
  Base-rev→HEAD currently has 867 base-only keys and only 35 property roots among them: the `search_index`
  (32) and `normalized_phone` (3) entries above, and nothing else. The other 832 sit under nine roots whose
  own key is present on both sides, every one a property that was an inline `{ … }` at base and is a
  `Pick<Model, K>` at head — `Pick<>` has no `{` to descend into, so its members stop being keyed
  separately (see [Aliases and inline-object members](#aliases-and-inline-object-members)). Those are
  noise, not signal. Re-measure rather than quoting these counts; they move whenever any property's shape
  changes.

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
