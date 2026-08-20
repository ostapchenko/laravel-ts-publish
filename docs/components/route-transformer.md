# RouteTransformer

> User-facing docs: [README § Routes](../../README.md#routes). Verified by
> [the type-inference gates](../testing/type-inference-gates.md).

`AbeTwoThree\LaravelTsPublish\Transformers\RouteTransformer` builds each controller action's
`RouteActionData`, including the `_routeKey` a model-bound parameter emits so the generated
`defineRoute()` helper knows which model property to read when a caller passes a model instance
instead of a scalar. This doc is scoped to that one piece: how a model-bound parameter's route key
is resolved.

## `resolveBindingField()`'s resolution order

`resolveBindingField()` (`src/Transformers/RouteTransformer.php`) resolves the `_routeKey` for a
single route parameter, checked in this order — the first hit wins:

1. **Explicit `{param:field}` binding.** `Route::bindingFieldFor($paramName)` reads Laravel's own
   explicit binding syntax (`Route::get('/articles/{article:slug}', ...)`). If present, it wins
   outright — nothing about the model itself is consulted.
2. **Not a typed model parameter.** If the reflected parameter has no class type, or the type isn't
   a `Model` subclass, there is no route key to resolve at all — the method returns `null`.
3. **The `overridesRouteKey()` gate.** Before instantiating the model, `overridesRouteKey()` checks
   whether the class *would* answer anything other than `'id'`. See below.
4. **Instantiate and ask.** Only when the gate says yes does `resolveBindingField()` build (or reuse
   a cached) instance and call `$instance->getRouteKeyName()` for the real answer. Otherwise it
   short-circuits to the literal `'id'` without ever constructing the model.

## Why the gate exists: instantiation is the expensive step

Building a model instance runs its constructor, which in Laravel 13 also resolves several of the
`Illuminate\Database\Eloquent\Attributes` class attributes (`#[Table]`, `#[Connection]`, etc.) via
`initializeModelAttributes()`. Doing that for every model-typed route parameter in the entire route
tree — most of which key by the default `'id'` and gain nothing from asking — would be wasted work
at generation time. `overridesRouteKey()` is a cheap, purely-reflective pre-check that answers "would
instantiating this model produce something other than `'id'`?" without paying the instantiation cost
for the common case.

## The four signals `overridesRouteKey()` checks

`overridesRouteKey(string $className): bool` returns `true` — meaning instantiation is worth it — the
moment any of these four hold, and `false` (skip straight to `'id'`) only when none do:

1. `getRouteKeyName()` is declared somewhere other than `Illuminate\Database\Eloquent\Model` itself.
2. `getKeyName()` is declared somewhere other than `Model` — Eloquent's own `getRouteKeyName()`
   delegates to `getKeyName()` by default, so an override there changes the answer just as much as
   overriding `getRouteKeyName()` directly.
3. `$primaryKey` is declared somewhere other than `Model` — the property `getKeyName()` itself reads
   by default.
4. The class carries Laravel 13's `#[RouteKey]` class attribute (`Illuminate\Database\Eloquent\Attributes\RouteKey`),
   read via `ReflectionClass::getAttributes()`. Laravel's own `Model::getRouteKeyName()` resolves this
   attribute when none of the first three overrides are present, so a model carrying *only* the
   attribute needs this fourth check — without it, none of the first three signals fire and the gate
   would wrongly report `'id'` even though instantiating the model and calling `getRouteKeyName()`
   would return the attribute's key.

All four checks are purely reflective — no model gets constructed to evaluate them — which is what
keeps the gate cheap enough to run for every model-typed route parameter.

The `#[RouteKey]` attribute does not exist before Laravel 13.0.0, so signal 4 is guarded by
`class_exists()` on its string FQCN rather than a `use` import. See [Version-guarded Laravel
classes](../laravel-version-guards.md) for the guard's registry row and when it can be converted to
a plain import.
