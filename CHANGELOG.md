# Changelog

## 0.3.2

Backward compatible: nothing that worked in 0.3.1 changes.

- `ModelType::resolveCount()`: how many rows `resolveList()` would return for a
  `query` with no limit, counted in SQL (`SELECT COUNT(*)`) over the same selector,
  `scope()` and `authorize(VERB_LIST, ...)`. The query's `limit`, `skip` and `sort`
  are ignored, so a list's query can be passed as it is; a bad selector is the same
  client-safe `UserError` as for the list. For paging: a client can tell the last
  page, and say "showing 50 of 312".
- `GraphQLUtils::createCountField($name, $type, $method = 'resolveCount')`: an
  `Int!` field resolved by that method, for `<entity>Count` beside `<entity>List`.
  The generator does not write count entries; add them by hand (README, "Counting
  for paging").

## 0.3.1

Backward compatible: every `sort` item that worked in 0.3.0 sorts as it did.

- A `sort` item can carry a direction: `"-field"` or `"field desc"` sorts
  descending, `"field"` or `"field asc"` ascending; the direction is
  case-insensitive. Until now every string item sorted ascending, so a list could
  not be read newest first.
- A bad `sort` item (an unknown field, an unknown direction, `"-field desc"`, more
  than two words) is a client-safe `UserError` whose message starts
  `Argument 'query.sort' item '<item>'`, says what is wrong with it and lists the
  model's sortable fields.

## 0.3.0

For the first consumer's third release (Release 3, billing). Default output is
unchanged.

- `--without-update <names>` and `--without-delete <names>`: drop an entity's
  `<entity>Update` or `<entity>Delete` mutation, everything else generated as before.
  `--without-update` also drops the Update input files (`Base/<Entity>UpdateInputBase.php`,
  `<Entity>UpdateInput.php`) and needs `--mutations create-update` (exit 2 under
  `upsert`, naming the conflict); `--without-delete` works under either mutations
  mode. Naming an entity no model produces is exit 2, as for `--only` / `--readonly` /
  `--input-only`. Switching an entity to `--without-update` reports its now-stale
  Update input files as orphaned, not deleted, exactly like the 0.2.0 `--input-only`
  stale reporting; a schema entry orphaned by the switch is reported as orphaned as
  before. `ModelTypeTestCase`'s generated lifecycle test skips the update and/or
  delete step cleanly for such an entity — no `markTestIncomplete` noise for a step
  that does not exist, only for the pre-existing case (update applicable but nothing
  to exercise it with).

## 0.2.1

Fix only, for the first consumer's second release. Default output is unchanged for
every entry that already fit on one line.

- `SchemaEditor::entryLines()` now wraps a `GraphQLUtils::createListField(...)` or
  `->addArgument(...)` call PSR-12 style (one argument per line, closing paren on its
  own) whenever it, at the indentation it will actually be written with, would exceed
  120 columns — the schema's own line length limit, which a generated entry could
  previously exceed with no way to satisfy it short of hand-editing generated code.
  Affects entities/fields long enough to push a line past 120 columns (e.g.
  `CreditStatus`, `PaymentTerms`), fully qualified names written where a short name
  cannot be imported, and the "paste these entries by hand" fallback, which uses the
  same wrapping.

## 0.2.0

For the first consumer's second release (`saygoweb/frontaccounting-module-graphql`,
Release 2). Default output is unchanged except for date properties (below).

- `--mutations create-update`: `<entity>Create` / `<entity>Update` with
  `<Entity>CreateInput` (no key; `@required` properties non-null) and
  `<Entity>UpdateInput` (key required; fields left out are left as they were), in
  place of `<entity>Upsert`. Runtime: `ModelType::resolveCreate()`,
  `ModelType::resolveUpdate()`. `ModelTypeTestCase` tests both.
- `--input-only <names>`: the Input(s) only, no schema entries; with `--readonly`,
  the read-only Type and its list as well.
- `Date` scalar (`Anorm\GraphQL\Type\DateType::instance()`), ISO `YYYY-MM-DD`. A
  property declared `\DateTimeInterface` or `\DateTimeImmutable` (or a class
  implementing it) is now a `Date` field; in 0.1 it was a `String`. A natively typed
  `\DateTime` property is left as `String`, since `parseValue()`/`parseLiteral()`
  hand back a `\DateTimeImmutable`, which such a property cannot take. `DateType`'s
  constructor is private, so only `DateType::instance()` — never `new DateType(...)`
  or a container lookup — can build one. MySQL's zero date (`0000-00-00`) serializes
  as `null` whether it arrives as a string or, through a model's date transformer, as
  the `\DateTime` it rolls over to.

## 0.1.0 — first alpha

The first tagged release, for its first consumer (`saygoweb/frontaccounting-module-graphql`).
Everything before it was pre-release work on `main`.

- `anorm-graphql make`: Types, Inputs, tests and `ApiSchema.php` entries from Anorm models.
- Runtime: `ModelType` (list, upsert, delete; `authorize`, `beforeWrite`, `scope`,
  `newModel`), `GraphQLUtils`, `MangoInput`, `FieldBuilder`, `ObjectBuilder`.
- `ModelTypeTestCase` for generated tests, with rollback and the non-transactional
  table check.
- `--type-base <class>`: every generated `<Entity>TypeBase` extends a project class
  instead of `ModelType`. Validated before anything is written.

Requires PHP `^7.4 || ^8.0`, `saygoweb/anorm ^3.2.1`, `webonyx/graphql-php ^15.32.3`.
0.x releases may change anything; 1.0.0 follows once the first consumer is built.
