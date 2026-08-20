# ControllerPaginatorAnalyzer

> User-facing docs: [README § Inertia](../../README.md#inertia). Verified by
> [the type-inference gates](../testing/type-inference-gates.md).

`AbeTwoThree\LaravelTsPublish\Analyzers\Inertia\ControllerPaginatorAnalyzer` walks a controller
action's method body as PHP AST (not reflection) to find which `Inertia::render()` props are
backed by a Laravel paginator, so `InertiaPageAnalyzer` can rewrite those props to
`JsonResourcePaginator<X>` / `& ResourcePagination` instead of leaving them as a bare resource or
collection type. This doc is scoped to that one piece: how a paginator call is traced back to its
model, and which forms of that call the analyzer can and cannot see.

## Three public entry points

The analyzer is constructed with `(class-string $controllerClass, string $methodName)` — a
class-string, not a `ReflectionClass` — and exposes three methods, all consumed by
`InertiaPageAnalyzer::analyze()`:

1. **`analyze(): array<string, class-string>`** — prop key => model FQCN for a bare-variable prop
   (`'posts' => $posts`) whose variable was itself assigned from a paginator call, plus prop key =>
   resource FQCN for a `SomeResource::collection($x)` prop that `resolveStaticCollectionProps()`
   classified as `nonPaginated`. That predicate has two disjuncts, not one: the argument is neither a
   `Variable` present in `$varModelMap` **nor** an expression `resolveInlinePaginatorModel()` resolves.
   `InertiaPreserveKeysController::anonymousInlinePaginated()` is the live counterexample to reading
   it as "not a paginated variable" — `PreserveKeysTeamResource::collection(Team::query()->paginate(10))`
   passes no variable at all, and still lands in `paginated`.
2. **`analyzePaginatedResourceProps(): array<string, class-string<object>>`** — prop key =>
   resource FQCN for `'key' => new SomeResource($paginated)` constructor props, where `$paginated`
   is either a paginated variable or an inline paginator call.
3. **`analyzePaginatedStaticCollectionProps(): array<string, class-string>`** — prop key =>
   resource FQCN for `'key' => SomeResource::collection($paginated)` props, same two argument forms.

All three share one method context: the parsed `ClassMethod` AST, a `NodeFinder`, and `$varModelMap`
— the variable-name => model FQCN map built once by `resolveVariableModels()`. `buildMethodContext()`
constructs it and caches nothing; the caching lives in `getMethodContext()`, which memoises the result
in `$resolvedMethodContext` behind a `$methodContextBuilt` flag so a `null` context is cached too.

## `PAGINATOR_METHODS`

A call counts as pagination when its method name is one of `paginate`, `simplePaginate`, or
`cursorPaginate` (`ControllerPaginatorAnalyzer::PAGINATOR_METHODS`). Any other terminal call —
`get()`, `all()`, `first()` — leaves the chain unrecognised as a paginator.

## The variable-assignment path

`resolveVariableModels()` scans every `Assign` node in the method body. When the right-hand side is
a `MethodCall` whose name is in `PAGINATOR_METHODS`, it walks the call's receiver through
`resolveModelFromChain()` and, on success, records `$varModelMap[$varName] = $modelFqcn`:

```php
$teams = Team::latest()->paginate(10);

return Inertia::render('Teams/Index', [
    'teams' => new TeamCollection($teams),
]);
```

`resolvePaginatedResourceConstructorProps()` and `resolveStaticCollectionProps()` both consult
`$varModelMap` when a prop's argument is a `Variable` — that is how `$teams` above resolves to a
paginator even though neither method re-walks the assignment itself.

## The inline path

A paginator call written directly as the constructor/collection argument — with **no** intermediate
variable — has no `Assign` node for `resolveVariableModels()` to find:

```php
return Inertia::render('Teams/Index', [
    'teams' => new TeamCollection(Team::query()->paginate(10)),
]);
```

`resolveInlinePaginatorModel(Expr $expr): ?class-string<Model>` handles this case directly: it
checks that `$expr` is a `MethodCall` whose name is in `PAGINATOR_METHODS`, then hands the call's
receiver to `resolveModelFromChain()` — the same resolver the variable-assignment path uses.
`resolvePaginatedResourceConstructorProps()` and `resolveStaticCollectionProps()` each try the
`Variable`/`$varModelMap` branch first and fall back to `resolveInlinePaginatorModel()`, so a prop
is recognised as paginated whichever form produced it.

## `resolveModelFromChain()`'s receiver-agnostic contract

`resolveModelFromChain(Expr $node): ?class-string<Model>` recurses through `MethodCall` nodes until
it reaches a `StaticCall`, then validates that the called class exists and is a `Model` subclass.
Nothing about it assumes the chain originated from a variable — it only ever inspects the
expression tree rooted at whatever `Expr` it is given. That is what let
`resolveInlinePaginatorModel()` reuse it unchanged: an inline `Team::query()->paginate(10)` and a
variable-assigned `$teams = Team::query()->paginate(10)` present `resolveModelFromChain()` with the
identical `Team::query()->paginate(10)` receiver chain, just reached from two different call sites.

## What still isn't recognised: indirection through a variable

Only a *direct* chain resolves. Once the query builder itself is assigned to a variable before the
paginator call, `resolveModelFromChain()` hits a `Variable` node — not a `StaticCall` — and returns
`null`:

```php
$q = Post::query();

return Inertia::render('Posts/Index', [
    'posts' => new PostCollection($q->paginate(10)),
]);
```

Here `resolveModelFromChain()` never sees `Post::query()`; it sees `$q`, which it does not resolve.
Neither the variable-assignment path nor the inline path added by this analyzer follows a query
builder through an intermediate variable. This is a known, accepted gap, not an oversight — see the
`resolveVariableModels()` docblock.

## An unrecognised paginator historically produced a *wrong* type, not no type

Before this analyzer's coverage improved, a paginator call it failed to recognise was not simply
skipped. `resolvePaginatedResourceConstructorProps()` and `resolveStaticCollectionProps()` both
default an unresolved prop to "not paginated" rather than "unknown" — the prop still gets a type
from the ordinary resource/collection analysis, just without the paginator wrapper. For a prop that
*is* actually paginated at runtime, that is a **silent misclassification**: the generated type
names a bare resource or `AnonymousResourceCollection<X>` where `JsonResourcePaginator<X>` (or `&
ResourcePagination`) is correct, and nothing in the pipeline flags the mismatch. This is why the
inline-paginator gap mattered even though every field still ended up typed as *something* — the
type was confidently wrong, not honestly absent.
