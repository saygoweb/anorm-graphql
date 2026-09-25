# Changelog

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
  property declared `\DateTimeInterface` (or a class implementing it) is now a
  `Date` field; in 0.1 it was a `String`.

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
