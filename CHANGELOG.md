# Changelog

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
