# Changelog

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
