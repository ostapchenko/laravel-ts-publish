# AST Engine

> Skeleton page. Task 26 completes the lead, `## Dispatch semantics`, `## AnalysisScope`,
> `## Dependency recording policy`, `## MethodAnalysis`, and `## Public API` sections. Task 23
> writes `## Handler ordering` now, in the same PR that creates the ordering contract it documents.

## Handler ordering

`ExpressionDispatcher::dispatch()` tries registered handlers, in registration order, for an
expression's concrete node class, and returns the first non-null result —
`ExpressionHandler::resolve()` returning `null` means DECLINE, fall through to the next candidate.
Order is load-bearing wherever a node class is claimed by more than one handler: a reordering that
changes which handler wins for a shared class is a silent behavior regression, not a refactor.

`ResourceExpressionHandlers::make()` builds the resource profile — all 22 handlers extracted from
the legacy `analyzeValueExpression()` guard chain across Tasks 14-22, in the exact order the chain
checked them. `ResourceExpressionHandlers::generic()` is that same list minus the three
resource-only handlers (`ConditionalMethodHandler`, `ToResourceHandler`, `RelationFilterHandler`)
— every other handler is class-agnostic and safe to reuse outside a resource's `toArray()`.

The executable ordering contract lives in `tests/Unit/Ast/ResourceExpressionHandlersTest.php`:

- One test asserts `make()`'s exact class-name sequence, so an accidental reorder fails a test
  instead of silently changing generated output.
- One test asserts `generic()`'s exclusion set and relative order.
- Two tests pin the *behavioral* precedence between handlers that both really claim a shared node
  class — proven by mutation: swap the pinned pair, watch the pinned test fail, revert.
  - `FirstClassCallableHandler` before `ConditionalMethodHandler` for a first-class-callable
    `$this->when(...)`. `ConditionalMethodHandler::isThisMethodCall()` matches on method name
    alone, ignoring arguments, so it also claims this shape; if it ran first it would call
    `MethodCall::getArgs()`, which asserts `!isFirstClassCallable()` and fatals.
  - `RelationFilterHandler` before `MethodChainHandler` for `$this->relation?->only([...])`.
    `MethodChainHandler`'s floor is `ValueResult::unknown()`, never `null`, so it always claims
    every `NullsafeMethodCall` — if it ran first it would win this one too, degrading a `Pick<>`
    reference to a plain reflected type.
