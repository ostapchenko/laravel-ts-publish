# ADR: Freeze Laravel Surveyor/Ranger and exit in stages

**Status:** accepted 2026-08-31. Amended by each exit stage (see Log).

## Context

`laravel/surveyor ^0.1.9` and `laravel/ranger ^0.1.12` are production dependencies used by
`BroadcastEventTransformer`, `InertiaPageAnalyzer`, `InertiaSharedDataAnalyzer` and `SurveyorTypeMapper`.
Upstream moved to surveyor v0.3.0 / ranger v0.5.0 and renamed `ClassResult` to `ClassLikeResult`; the
`^0.1` carets hide this from CI. A measured bump regressed 12 committed `.ts` files and improved none.
On user-shaped input (starter-kit `share()`, Eloquent finders, `Inertia::defer`, `compact()`, computed
`broadcastAs()`) the Surveyor path ships wrong or unimportable types the workbench never exercised.

## Decision

1. Freeze at surveyor v0.1.10 / ranger v0.1.12. No bump.
2. Harden the boundary so an upstream/Surveyor failure degrades one item with a warning instead of
   aborting the run (`SurveyorTypeMapper` fallback, `AnalysisWarnings`).
3. Move broadcast events onto the native `AstEngine` first, then Inertia shared data, then page props.
4. Remove both packages once page props are native.

## Exit gates

Each stage adds user-shape fixtures to the workbench and must show, on those fixtures: zero
unimportable tokens (TS2304 + TS2307), zero known-wrong types, no real-type-to-`unknown` regressions.
Intended golden changes are listed in the PR before it lands; an unlisted diff is a defect.

## Log

- 2026-08-31 — accepted.
- 2026-09-01 — broadcast events native. `BroadcastEventTransformer` no longer takes Surveyor's
  `Analyzer`; payloads come from `AstEngine` and the Echo name from `ReturnLiteralReader`. Trait-declared
  public properties stay deliberately excluded (parity with Surveyor; `#[TsExtends]` supplies them).
  Gate: three user-shape fixtures added first under Surveyor; only golden change is
  `ComputedNameEvent`'s Echo key, `"order."` → `.Workbench.App.Events.ComputedNameEvent`. All 17 event
  interfaces byte-identical; zero unimportable tokens; no real-type-to-`unknown` regressions.
