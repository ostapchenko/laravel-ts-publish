# Known gaps

Work that is deliberately not done, kept here so it survives the branch that deferred it. Each entry
says what the gap is, why it was left, and where in the code it lives. Nothing here is a regression —
these are limits and follow-ups that were understood and accepted at the time.

Add to this file rather than to a commit message when you decline something a reviewer raised.

## Correctness

### `analyzeThisMethodSpread()` leaks its recursion guard on a lookup miss

`ResourceAstAnalyzer::analyzeThisMethodSpread()` (`src/Analyzers/ResourceAstAnalyzer.php:578`) sets
`$scope->visitedSpreadMethods[$methodName] = true` at `:588`, *before* resolving the method's AST. When
`MethodLocator` misses, the method returns early without reaching the `finally` that clears the entry at
`:640`, so that name stays marked "on the stack" for the rest of the analysis and a later legitimate
spread of the same name degrades to an empty analysis.

No current fixture triggers it: a miss means the method does not exist, so nothing spreads it twice. Fix
by moving the guard-set after the successful lookup, or wrapping the lookup in the same `try`/`finally`,
with a fixture that spreads a missing method and then a real one of the same name.

### `$request->validated('key')` is never typed

The Inertia page path types `$request->url()`, `->user()`, `->integer()` and friends through the rule
table in `src/Ast/Handlers/KnownMethodRuleHandler.php:86`. `validated()` is absent from it, so
`Inertia::render('X', ['title' => $request->validated('title')])` — a headline user shape — ships
`title: unknown`.

Two blockers, both real:

- `AnalysisScope::$requestVarNames` (`src/Ast/AnalysisScope.php`) is an `array<string, true>` presence
  map, so the handler knows the variable *is* a `Request` but not *which* `FormRequest` subclass. The
  value has to widen to the class-string, which touches the seeding in
  `ResourceAstAnalyzer::resolveRequestVarNames()` and `AstEngine::bindingsFor()` plus their tests.
- Resolving the rules means calling `FormRequestRulesAnalyzer::analyze()` from inside an expression
  handler. That method instantiates the `FormRequest`, calls `rules()`, and mutates global auth state via
  `Auth::setUser()` / `Auth::forgetUser()` — running application code during type resolution, which is
  precisely the property the AST-engine unification was undertaken to remove.

Worth doing as its own change with that trade-off argued explicitly. Not something to fold into an
unrelated commit.

### `config()` residuals

`KnownFunctionCallHandler::resolveConfigCallType()` now types `config('key', $default)` from the default
expression when the key is unset, instead of answering `null`. Two narrower cases remain:

- A single-argument `config('unset.key')` still types as `null`. That is the live value at analysis time
  and there is no second argument to fall back on, but it is only as honest as the analysis-time config
  matching the consuming app's runtime config.
- Only positional argument order is understood. `config(default: 'x', key: 'k')` reads `$args[0]` as the
  key. Pre-existing, and the same hazard the single-argument path always had.
- A key explicitly **set to `null`** and given a default now types as the default, where Laravel's
  `Arr::get()` would return `null` at runtime. `Config::get()` cannot distinguish "absent" from "present
  and null" without a sentinel. Narrow, and strictly better than the bug it replaced — an unset key with
  a default was the common case and typed wrong. Fix by reading with a unique sentinel default if it
  ever matters.

### `EnumResource::collection()` inside a mixed ternary arm

`ResourceTransformer` (`src/Transformers/ResourceTransformer.php:472`) assumes the wrapping arm of a
mixed enum ternary is never `EnumResource::collection()`. Not true in general; the direct arm's own shape
is what decides the array suffix today.

## Tooling and CI

### The token gate type-checks one of the four generated trees

`tsconfig.json`'s `include` covers `data/default-example/**` and `tests/types/**` only, so
`.github/scripts/unimportable-token-gate.sh` never compiles `data/testing`,
`data/full-template-example` or `data/split-template-example`. An unimportable token — or a parse error,
the fail-open shape the gate was specifically hardened against — that appears in only one of those three
is invisible to it.

Low likelihood in practice, because all four trees come from one pipeline over one fixture set and
diverge only by template and output-directory config. Fix by widening `include` to `data/**` and
re-baselining the three counts, which *will* change them: the same token appears up to four times.
Re-baselining a CI gate deserves its own commit.

### `laravel-ts-global.ts` emits `#[TsExtends]` imports without the `extends` clause

Four TS6196 (`declared but never used`) survive in the generated tree, and no gate counts them. Three are
the same defect: `laravel-ts-global.ts` carries the `#[TsExtends]` import for broadcast events and form
requests — `BroadcastableEvent`, `FormRequestBase`, `HasValidationMeta` — while emitting
`export interface ServerCreated {`, `StringRulesRequest {` and `NumberRulesRequest {` with **no** `extends`
clause. The per-file flavor emits both halves correctly, so the global flavor silently loses the interface
composition and keeps a dangling import. The import half was a known side effect of `GlobalsWriter`'s
form-request loop; the missing `extends` is the other half and is not deliberate.

The fourth is unrelated: an unused `RoleType` import in `to-array-casts-resource.ts`.

Not fixed here because fixing it changes what the package emits and moves the golden tree, which does not
belong in a commit whose whole purpose is to make the gate stricter without touching output. Adding TS6196
to `unimportable-token-gate.sh` should follow the fix, not precede it — armed today it would fail at 4.

### The `publish-bench` CI job has never executed

`.github/scripts/publish-bench.sh` and its workflow job shipped without a PR ever running them. On the
first PR that exercises it, confirm `apt-get install -y hyperfine` still succeeds on `ubuntu-latest`, the
merge-base resolves sensibly, and the job fits its time budget. The local `composer bench` path *is*
verified. Two cosmetic gaps in the script: the ratio line has no grep-able `PASS -` / `FAIL -` prefix
like its sibling gates, and there is no arg-count check on `BASE_DIR` / `HEAD_DIR`.

### PHPStan's result cache crashes under a 128M `memory_limit`

After tracked files change, revalidating the gitignored `build/phpstan/resultCache.php` can OOM — often
reported as `Undefined constant Larastan\Larastan\LARAVEL_VERSION` from inside Larastan's stub-file
extension, which is a misleading symptom for the real cause. The remedy is to delete the cache, run one
`--memory-limit=-1` pass, and re-run. Options for making it stop: raise the limit in `phpstan.neon.dist`,
add a `composer analyse:fresh` helper, or document the symptom in `AGENTS.md`.

### Run Tolki's `readme-build` before the next release

`packages/ts/README.md` in the docs repo is an aggregate of the VitePress pages, refreshed only by that
build. It still carries pre-migration prose describing Surveyor as the live analyzer, contradicting the
`ts/` sources it is built from. Nothing to hand-edit — run the build so the published npm README matches
the site.

Two link problems to settle in the same pass, neither introduced by that build:

- **`ts/analyzer-api.md` is missing from `packages/ts/package.json`'s `tolki.docs` list**, so the
  aggregate never includes the page while six `](./analyzer-api.md)` links across the included sources
  point at it. Add it to the list, in reading order.
- **Every relative `](./*.md)` link in the aggregate is dangling on npm** — 122 of them today, led by
  `./enums.md` (18) and `./models.md` (17). They resolve on the VitePress site and nowhere else. The real
  fix is rewriting them to absolute `https://tolki.abe.dev/ts/…` URLs during the build. Long-standing and
  unrelated to any one page.

## Test coverage

### The `MethodCall` dispatch matrix is pinned by example, not exhaustively

Nine handlers claim `MethodCall` in `ResourceExpressionHandlers::handlers()`. Three ordered pairs have a
dedicated ordering pin in `tests/Unit/Ast/ResourceExpressionHandlersTest.php`; the rest are held only by
whichever end-to-end fixture happens to traverse them, and a mutation sweep found reorderings that change
output with zero test failures. The full per-node-class inventory — pinned, inert-proven, and unpinned —
is the "honest ordering inventory" table in
[`components/ast-engine.md`](components/ast-engine.md#the-honest-ordering-inventory). A generated pairwise matrix over the claimants — assert each pair's
resolved type, then swap and assert it changes — would close this properly. The three existing pins are
the pattern to follow.

### `substituteEnumType` / `substituteEnumResourceType` are a byte-identical pair

`InlineArrayHandler::substituteEnumType()` (`src/Ast/Handlers/InlineArrayHandler.php:370`) and
`ResourceTransformer::substituteEnumResourceType()` (`src/Transformers/ResourceTransformer.php:618`) are
the same 25-token body under two names. They span `src/Ast/` and `src/Transformers/`, which is why the
deduplication pass that closed twelve other groups legitimately left this one. If the copies drift,
enum-token substitution behaves one way inside an inline-array arm and another at the transformer. The
`Mirrors ResourceTransformer::substituteEnumResourceType()` comment on the handler is the pair's only
marker.

Related, and **not** a finding: five handlers each declare `return [MethodCall::class];` in
`nodeClasses()`. That is the interface working as intended. Do not consolidate it.

## Deliberate non-goals

- **Non-Inertia and JSON responses are never typed.** Only `Inertia::render()` page props and the
  shared-data middleware are analyzed.
- **`Carbon` maps to `string`, not `Date`.** Set by `TypeScriptMap.php`; changing it is a mapping
  decision that affects every feature, not an engine one.
- **No `ts-publish.analyzer.handlers` config key.** Users cannot append their own `ExpressionHandler`.
  Worth adding only if someone asks.

## Refactors worth doing, deliberately not done here

### `LaravelTsPublish.php` leaf-utility extraction

Roughly 400 lines of pure, stateless helpers could move out of the facade class into three files:

- `Support/JsEmitter.php` — `validJsObjectKey`, `safeJsIdentifier`, `toJsLiteral`, `routeArgsToJs`,
  `sanitizeJsDoc`, `formatJsDoc`, and `RESERVED_JS_IDENTIFIERS`
- `Support/TsTypeString.php` — `extractImportableTypes`, `aliasPropertyType`, `qualifyGlobalType`,
  `splitTopLevelUnion`, `rewriteAsEnumToType` (`TS_PRIMITIVES` stays on the facade as canonical)
- `Support/TsPaths.php` — `resolveRelativePath`, `namespaceToPath`, `relativeImportPath`,
  `sortImportPaths`, `importSortGroup`, `resolveClassFromFile`

**Every extracted method must keep a permanent thin facade delegation.** Published Blade views and the
Tolki docs call `LaravelTsPublish::x()` statically, and published views are user API, so no consumer
re-points.

Do **not** extract: `callCommandUsing` (documented API, static state), `resourceTypeName` (instance cache,
no container binding), `keyCase`, `typesMap` / `relationsMap` / `relationStrategy` (already delegating),
or the `TypeScriptTypeInfo` helpers (four load-bearing `@phpstan-import-type` headers and ~25 engine call
sites). The ~1,240-line type engine — `toTsType()` plus the docblock pipeline, mutually recursive over a
shared `$shapeExpansionStack` guard — stays put.

Separately: `relationsMap` has zero production consumers and is a deprecation candidate.
