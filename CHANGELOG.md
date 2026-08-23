# Changelog

All notable changes to `laravel-ts-publish` will be documented in this file.

## v2.4.0 - 2026-08-23

### What's Changed

Two rounds of analyzer work, both aimed at the same thing: resource types that match the JSON Laravel actually sends. The `except()` and `only()` renderings changed shape, several properties that resolved to `unknown` now resolve properly, and four type tokens TypeScript could not import are gone.

One output change worth reading before you upgrade. Relation `except()` now emits `Pick<Model, kept>` instead of `Omit<Model, excluded>`. The new type is narrower and matches the response, but any frontend code leaning on the old shape needs updating.

### API resources

- Relation `except()` emits the complement as a `Pick`, so `Omit<Post, 'created_at' | 'updated_at'>` becomes `Pick<Post, 'id' | 'title' | ...>`. `Omit` left mutators, relations, counts and `exists` flags in the type. The response only ever contains columns.
- A multi-model accessor filtered with `only()` references each arm's own model. `{ id: number; name: string } | null` becomes `Pick<crm.models.User, 'id' | 'name'> | Pick<app.models.User, 'id' | 'name'> | null`.
- Spreading a model inside an inline array intersects its `toArray()` with the sibling keys. `{ flag: boolean }[]` becomes `(Omit<app.models.User, 'flag'> & { flag: boolean })[]`.
- A to-many `whenLoaded()` parameter's spread types as `Record<number, Model>` instead of collapsing to the single element model.
- A resource subclass that declares no `toArray()` inherits the parent's body instead of generating an empty interface.
- A method called on a resource receiver that returns something other than `static`, `self` or `$this` resolves its real return shape. `parent_summary?: unknown` becomes `parent_summary?: { id: number }`.
- Enum resources inside inline array literals are substituted rather than rebuilt, so both arms of a mixed wrap/direct ternary survive: `{ status: app.enums.StatusType[] | app.enums.StatusType }`.
- Resources guessed by naming convention (`FooCollection` to `FooResource`) now have to be in the published set. A third-party or `#[TsExclude]`d class can no longer be named in a type that has no file to import. The Inertia page analyzer shares the same gate.

### Imports and naming

- Every occurrence of a same-basename type in one property is aliased, not just the first: `status_pair: { app: app.enums.StatusType; crm: crm.enums.StatusType }`.
- Morph relations in `ModelTransformer` go through the shared aliasing path instead of their own copy of it.
- `#[TsResource(name: 'Address')]` is honoured when the analyzer derives the reference, not only when the resource is transformed directly.
- Form request custom type imports now reach the globals file.
- A plain value object whose public properties are all typed inlines its shape. `location: Coordinate`, which nothing could import, becomes `location: { lat: number; lng: number }`.

### Inertia and form requests

- A paginator called inline in the `render()` props array is detected with no intermediate variable, so `Inertia::render('Teams/Index', ['teams' => new TeamCollection(Team::query()->paginate(10))])` still types as a paginator.
- `digits` and `decimal` count as numeric when coercing `in:` values. `['digits:1', 'in:1,2,3']` gives `1 | 2 | 3` instead of `'1' | '2' | '3'`.

* Feat/analyzer followups by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/62
* Feat/analyzer backlog by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/64

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v2.3.0...v2.4.0

## v2.3.0 - 2026-08-16

### What's Changed

This release is another large set of updates to make output more accurate and lower the amount of `unknown` output types.

Three areas got most of the work:

- resolving types that previously degraded to `unknown`
- teaching the resource analyzer more of what `toArray()` can express
- and completing the database column type map across all four drivers Laravel supports.

### Fewer `unknown` types

- `@phpstan-type` / `@phpstan-import-type` aliases now resolve, including the `Name = Definition` form.
- Castable-with-arguments cast strings (`AsEnumCollection:Status`) resolve to their inner type.
- An `Arrayable` DTO's own typed public properties become an object shape.
- `MorphTo` docblock generics resolve to a union, with a morph-name-keyed target map.
- Variables carry their model through — `whenLoaded()` closure params, `foreach`, chain terminals.

### Resources understand more of `toArray()`

- Five more conditional methods: `unless()`, `whenAppended()`, `whenExistsLoaded()`, `transform()`, `mergeUnless()`.
- `whenNull()` / `whenNotNull()` read their value argument instead of discarding it.
- An explicit default makes the property required, and unions the default's type in where resolvable.
- A bare `return $this->someMethod();` resolves transitively, same as its spread form.
- `#[PreserveKeys]` emits `Record<string, Resource>` instead of `Resource[]`.
- Relation `only()`/`except()` reference the model via `Pick<>`/`Omit<>` instead of re-deriving.
- `models.exclude_hidden` now applies to resource interfaces too.

### Models & the column type map

- ~30 more native column types — spatial, vector, binary, network, and legacy.
- Sized types (`varchar(255)`, `tinyint(1)`) now match the map exactly instead of a substring scan.
- Laravel 13's `#[Table]`, `#[Hidden]`, `#[Visible]`, `#[Appends]`, `#[Connection]` are honoured.

### Form Requests / Routes / Imports

- `required_array_keys`, `in_array_keys`, `array:a,b` resolve to a keyed object, not `unknown[]`.
- GET routes carry `head`, so `.head()` and `.form.head()` exist.
- New import-name registry gives collision-proof aliasing across namespaces.

* Feat/unknown inference second pass by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/59
* Feat/resource inference and laravel 13 attributes by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/60

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v2.2.0...v2.3.0

## v2.2.0 - 2026-08-08

### What's Changed

- A lot of improvements to type model & resource properties into TypeScript equivalent versions.
- Testing upgrades
- General clean up

* Feat/type inference unknowns by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/57

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v2.1.0...v2.2.0

## v2.1.0 - 2026-07-11

Fix docs & Laravel Boost skill to help AI understand how to create types and how to use them from this package.

You can install the skill on your Laravel application by re-running `boost:install`

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v2.0.3...v2.1.0

## v2.0.3 - 2026-07-10

### What's Changed

* Fix/writer mkdir race by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/56

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v2.0.2...v2.0.3

## v2.0.2 - 2026-07-10

### What's Changed

* fix: Handle cache dir creation race condition by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/55

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v2.0.1...v2.0.2

## v1.5.1 - 2026-07-09

### What's Changed

* Report ts:publish failures on stderr under --quiet
* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/abetwothree/laravel-ts-publish/pull/4
* Bump dependabot/fetch-metadata from 2.5.0 to 3.1.0 by @dependabot[bot] in https://github.com/abetwothree/laravel-ts-publish/pull/26
* Update awobaz/compoships requirement from ^2.5 to ^2.5 || ^3.0 by @dependabot[bot] in https://github.com/abetwothree/laravel-ts-publish/pull/29
* Bump actions/checkout from 6 to 7 by @dependabot[bot] in https://github.com/abetwothree/laravel-ts-publish/pull/51

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/abetwothree/laravel-ts-publish/pull/4

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.5.0...v1.5.1

## v2.0.1 - 2026-07-09

Report ts:publish failures on stderr under --quiet

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v2.0.0...v2.0.1

## v2.0.0 - 2026-07-04

### What's Changed

* Initial Routing  by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/20
* V2 breaking changes updates by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/24
* V2 Inertia features by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/27
* 2x inertia page return props by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/30
* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/abetwothree/laravel-ts-publish/pull/4
* Bump dependabot/fetch-metadata from 2.5.0 to 3.1.0 by @dependabot[bot] in https://github.com/abetwothree/laravel-ts-publish/pull/26
* Update awobaz/compoships requirement from ^2.5 to ^2.5 || ^3.0 by @dependabot[bot] in https://github.com/abetwothree/laravel-ts-publish/pull/29
* Form Requests  by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/47
* Broadcast channels by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/48
* Broadcast Events by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/49
* 2.x routing spec checks by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/50
* 2x Caching by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/52
* 2x inertia table UI by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/53
* Bump actions/checkout from 6 to 7 by @dependabot[bot] in https://github.com/abetwothree/laravel-ts-publish/pull/51
* 2.x by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/23

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/abetwothree/laravel-ts-publish/pull/4

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.5.0...v2.0.0

## v1.5.0 - 2026-05-23

### What's Changed

* Issue #43 resource->enums by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/45
* Preserve multiline comment formatting by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/46

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.4.7...v1.5.0

## v1.4.7 - 2026-05-22

### What's Changed

* PHP functions return types by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/41
* Conditional closure params by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/42

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.4.6...v1.4.7

## v1.4.6 - 2026-05-21

### What's Changed

* Fix Support "self" keyword in resources by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/39
* Ternary operator in resources by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/40

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.4.5...v1.4.6

## v1.4.5 - 2026-05-06

### What's Changed

* AST static methods support  by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/34

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.4.4...v1.4.5

## v1.4.4 - 2026-05-06

### What's Changed

* Further AST chain analysis for model methods by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/33

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.4.3...v1.4.4

## v1.4.3 - 2026-05-05

### What's Changed

* Resource AST nullsafe chains by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/32

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.4.2...v1.4.3

## v1.4.2 - 2026-04-25

### What's Changed

* Resource typing improvements by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/28

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.4.1...v1.4.2

## v1.4.1 - 2026-04-18

### What's Changed

* Many relationships fix for only & except on resources by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/25

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.4.0...v1.4.1

## v1.4.0 - 2026-04-16

### What's Changed

* Ast handle closure data return by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/21

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.3.1...v1.4.0

## v1.3.1 - 2026-04-07

Remove `no-op` reflection method

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.3.0...v1.3.1

## v1.3.0 - 2026-04-01

### What's Changed

* Appends attributes to model & resource @extends doc tag by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/17
* Model-less resource support by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/18

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.2.1...v1.3.0

## v1.2.1 - 2026-03-28

### What's Changed

* Globals namespace fixes by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/15

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.2.0...v1.2.1

## v1.2.0 - 2026-03-27

### What's Changed

* Mutators attributes doc block types by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/13
* Ability to extend interfaces by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/12
* Greater support for model `only` & `exclude` methods with relations by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/14

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.1.2...v1.2.0

## v1.1.2 - 2026-03-24

### What's Changed

* Edge cases with resources by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/11

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.1.1...v1.1.2

## v1.1.1 - 2026-03-23

### What's Changed

* Support Laravel 13 by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/10
* Implement nullable enums and other casted values by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/9

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.1.0...v1.1.1

## Support for transforming Eloquent API Resources to TypeScript declaration files - 2026-03-23

### What's Changed

* Eloquent API Resources to TypeScript by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/8

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v1.0.1...v1.1.0

## v1 release 🎉  - 2026-03-17

### What's Changed

* Naming conflicts for published TS files fixes  by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/1
* Several large features before final release  by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/2
* Nullable relationships setup and testing by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/3
* Handle nullable relations with composite foreign keys by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/5
* Add ability to exclude content by @abetwothree in https://github.com/abetwothree/laravel-ts-publish/pull/6

### New Contributors

* @abetwothree made their first contribution in https://github.com/abetwothree/laravel-ts-publish/pull/1

**Full Changelog**: https://github.com/abetwothree/laravel-ts-publish/compare/v0.0.0...v1.0.0
