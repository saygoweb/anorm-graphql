# anorm-graphql — design

Date: 2026-09-21
Status: approved design, pre-implementation. Revised 2026-09-21 after the plan's code was
prototyped and run; each revision is marked *(revised)*.

## 1. Purpose

`saygoweb/anorm-graphql` generates the GraphQL Types, Inputs and tests for a PHP
GraphQL API from [Anorm](https://github.com/saygoweb/anorm) models, and ships the
small runtime those generated classes depend on.

The models may themselves have been generated from an existing database by
`anorm make`, so the full path is: database → `anorm make` → models →
`anorm-graphql make` → Types, Inputs, tests, and `ApiSchema.php` entries.

The stack is `webonyx/graphql-php` 15, `php-di/php-di` and composer PSR-4
autoloading. *(revised)* emdc-events and saygoweb.com-my are on webonyx 14 with
`simpod/graphql-utils`; this package cannot follow them there, see §3.

The use case prompting the work is a GraphQL API for FrontAccounting, in
`frontaccounting/modules/graphql`.

### Conventions this design adopts

- **Everything is a list.** There is no single-item query. A "view" is a list of
  one, selected with a Mango query.
- **`GraphQLUtils` is the way fields are wired.** Its `createField`,
  `createListField` and `methodResolver` move into the runtime unchanged.
- **The `ListorType` / `MutatorType` / `MutatableType` pattern is not used.**
- **Query and Mutation entries in `ApiSchema.php` are alphabetical** by field name.

### Goals for v1

1. A runtime library that ends the copy-paste of `GraphQLUtils`, `MangoInput` and
   `Mapper` between projects.
2. A generator producing, per model, a Type and an Input using the generation-gap
   pattern, so regeneration never destroys hand-written code.
3. Mango filtering on every list.
4. A generated test per entity, extending a shared abstract test case.
5. In-place maintenance of the Query and Mutation entries in `ApiSchema.php`.
6. Docker tooling matching Anorm's `docker/anorm`.

### Non-goals for v1

- Relationship fields (nested `client { reseller { name } }`) and batch loading.
- Models with composite or absent primary keys. They are skipped with a reason.
- Separate Create and Update input types. One Input; upsert decides by `id`.
- A `.devcontainer/` and a CI workflow file.
- Deleting anything. Orphaned files and schema entries are reported, never removed.

## 2. Schema surface

Per entity, using `Client` as the example:

```graphql
type Query {
  clientList(query: MangoInput): [ClientType!]!
}

type Mutation {
  clientDelete(id: [ID!]!): [ClientType!]!
  clientUpsert(input: [ClientInput!]!): [ClientType!]!
}
```

- `clientList` with no `query` returns every row.
- `clientUpsert` creates a row when its input has no `id`, and updates when it has
  one. It returns the written rows.
- `clientDelete` returns the rows as they were immediately before deletion.
- A read-only entity has `clientList` only, and no `ClientInput`.

`MangoInput` keeps the shape already in use:

```graphql
input MangoInput {
  action: String
  selector: String   # JSON-encoded Mango selector
  limit: Int
  skip: Int
  sort: [String]
}
```

## 3. Package layout

```
saygoweb/anorm-graphql
  bin/anorm-graphql.php             CLI entry point
  src/                              Anorm\GraphQL\            runtime (autoload)
    Builder/FieldBuilder.php        (revised) in place of simpod/graphql-utils
    Builder/ObjectBuilder.php
    GraphQLUtils.php
    Mapper.php
    ModelType.php
    Type/MangoInput.php
    Testing/ModelTypeTestCase.php
  tools/src/                        Anorm\GraphQL\Tools\      generator (autoload)
    NullPdo.php
    TypeMaker.php
    TypeMakerOptions.php
    TypeInfo.php
    TypeInfoBuilder.php
    FileWriter.php
    Writer/Php.php
    Writer/TypeBaseWriter.php
    Writer/TypeWriter.php
    Writer/InputBaseWriter.php
    Writer/InputWriter.php
    Writer/TestWriter.php
    Writer/TestCaseWriter.php
    Schema/SchemaEditor.php
    Schema/SchemaScaffolder.php
    Schema/FieldsArrayLocator.php
    Schema/FieldsArray.php
    Schema/FieldsEntry.php
    Schema/SchemaEditResult.php
  test/
    tools/          no database
    runtime/        no database
    integration/    database
    Fixtures/       capitalised: its classes are PSR-4 autoloaded (revised)
  docker/
  docs/
```

`composer.json`:

- `require` *(revised twice)*: `php: ^7.4 || ^8.0`, `saygoweb/anorm: ^3.2`,
  `webonyx/graphql-php: ^15.32.3`, `php-di/php-di: ^6.0`,
  `wp-cli/php-cli-tools: ^0.11.10`. **No `simpod/graphql-utils`.**

  Composer refuses every webonyx 14.x release over three denial-of-service
  advisories (GHSA-r7cg-qjjm-xhqq unbounded parser recursion, high;
  GHSA-fc86-6rv6-2jpm quadratic `OverlappingFieldsCanBeMerged`, high;
  CVE-2026-40476, medium), fixed only in 15.32.3 and later. webonyx 15 still supports
  PHP 7.4, but `simpod/graphql-utils` does not: 0.5.3 is its last release for PHP 7.4
  and requires webonyx `^14`; 0.6 and later need PHP 8.1. The user chose webonyx 15
  without simpod over raising the floor to PHP 8.1 (FrontAccounting and both existing
  APIs are on 7.4) and over ignoring the advisories.

  The runtime therefore ships `Anorm\GraphQL\Builder\FieldBuilder` and
  `ObjectBuilder`, offering the simpod calls this codebase uses (`create`,
  `setDescription`, `addArgument`, `setResolver`, `setDeprecationReason`, `setFields`,
  `build`), so `GraphQLUtils` and the generated code read as designed.

  Consequence: emdc-events and saygoweb.com-my (webonyx 14 + simpod) and inthefish
  (webonyx 0.13) cannot adopt this package until they move to webonyx 15, at which
  point their simpod imports become `Anorm\GraphQL\Builder` imports.
- `require-dev`: `phpunit/phpunit: ^9.6`, `squizlabs/php_codesniffer`,
  `phpstan/phpstan`.
- `suggest`: `phpunit/phpunit`, needed by consumers that extend `ModelTypeTestCase`.
- `bin`: `bin/anorm-graphql.php`.
- Both `src/` and `tools/src/` are in `autoload`, as in Anorm, so the binary works
  when installed as a dependency.

`saygoweb/anorm ^3.2` is sufficient: `Anorm\Schema\PropertyType` and
`Anorm\Tools\ModelLocator` are both in the tagged `v3.2.0`.

The generator is a separate binary rather than a subcommand of `anorm`, so Anorm
stays free of any GraphQL dependency.

## 4. Runtime

### 4.1 `GraphQLUtils`

Moved from emdc-events: `methodResolver`, `createField`, `createListField`.
Namespace changes to `Anorm\GraphQL`, and *(revised)* the `FieldBuilder` it returns is
`Anorm\GraphQL\Builder\FieldBuilder`; behaviour does not change.

### 4.2 `Mapper`

`toArray($model, $exclude = [])` and `toModel(&$model, $array, $exclude = [])`,
skipping properties prefixed `_`.

**Deliberate behaviour change from the existing copies:** `toArray` keeps `null` as
`null`. The existing copies convert `null` to `''`, which makes graphql-php's `Int`
serialiser throw and is what forced the `intOrNull` workaround in saygoweb.com-my.
With `null` preserved, `intOrNull` is unnecessary and is not carried over.

### 4.3 `ModelType`

Abstract, extends `GraphQL\Type\Definition\ObjectType`. It holds every piece of
resolver logic that is the same for all entities, so it is written and tested once,
and fixes reach consumers through `composer update` rather than regeneration.

```php
abstract protected function modelClass(): string;
abstract protected function fields(): array;

protected function newModel(Container $context): Model;

public function resolveList($root, $args, Container $context): array;
public function resolveUpsert($root, $args, Container $context): array;
public function resolveDelete($root, $args, Container $context): array;

protected function authorize(string $verb, ?Model $model, Container $context): void;
protected function beforeWrite(Model $model, array $input, bool $isUpdate, Container $context): void;
```

- `newModel` defaults to `new $class($context->get(\PDO::class))`. Projects whose
  models take their PDO from `Anorm::pdo()` override it.
- `authorize` and `beforeWrite` do nothing by default. They are the intended
  override points for Rights checks and for stamping columns such as `dtu` / `uu`.
  Verbs are `list`, `create`, `edit`, `delete`. `$model` is null for `list` and
  `create`.

**`resolveList`**

1. `authorize('list', null, $context)`.
2. Build a Mango array from `$args['query']`, JSON-decoding `selector`. Invalid JSON
   throws a client-safe error naming the argument.
3. *(revised)* **Whitelist every field name.** Each selector key at any depth that is
   not a `$operator`, and each `sort` field, must be a key of the model's property
   map; anything else throws a client-safe error. This is a security boundary:
   Anorm's Mango parser puts a name it does not recognise into the SQL between
   backticks, unescaped, and these names arrive from the API's clients.
4. `DataMapper::find($class, $pdo)->byMango(MangoQuery::fromArray(...))->some()`.
   With no `query`, `byMango` is not called.
5. `Mapper::toArray` each row.

**`resolveUpsert`**

For each element of `$args['input']`, in order:

1. If the key property is present and non-empty: `readOrThrow`, then
   `authorize('edit', $model, $context)`. Otherwise `authorize('create', null, ...)`.
2. `Mapper::toModel`.
3. `beforeWrite($model, $input, $isUpdate, $context)`.
4. `write()`.

Returns the written rows through `Mapper::toArray`. *(revised)* The model is returned
as written, not read back: Anorm inserts every property, so a column `DEFAULT` never
applies and a read-back would add a query per row for nothing.

*(revised)* "Not found" is a `GraphQL\Error\UserError`, not the plain `\Exception` of
`readOrThrow`, because webonyx reports anything else to the client as "Internal
server error".

**`resolveDelete`**

For each id: `readOrThrow`, `authorize('delete', $model, $context)`, capture
`Mapper::toArray($model)`, delete. Returns the captured rows.

**Transactions.** `resolveUpsert` and `resolveDelete` are all-or-nothing: any row
failing rolls back every row in that call. If the PDO is already in a transaction
the resolver uses a savepoint instead of `beginTransaction`, releasing it on success
and rolling back to it on failure. This is required so the generated tests can wrap
each test in an outer transaction (section 8.2), and it lets a consumer wrap several
mutations in one transaction of its own.

### 4.4 `Type\MangoInput`

As in section 2. Moved from emdc-events.

## 5. Generated code

### 5.1 Files per entity

| File | Written |
|---|---|
| `<output>/Client/Base/ClientTypeBase.php` | every run |
| `<output>/Client/Base/ClientInputBase.php` | every run; not if read-only |
| `<output>/Client/ClientType.php` | only if absent, or `--force` |
| `<output>/Client/ClientInput.php` | only if absent, or `--force`; not if read-only |
| `<tests>/ClientTypeTest.php` | only if absent, or `--force` |

Once per project, only if absent: `<tests>/TestCase.php` (section 8.2).

### 5.2 Regenerated base

```php
<?php
// GENERATED by anorm-graphql — do not edit. Changes belong in ClientType.php.

namespace App\GraphQL\Type\Client\Base;

abstract class ClientTypeBase extends ModelType
{
    public function __construct()
    {
        parent::__construct(
            ObjectBuilder::create('ClientType')->setFields($this->fields())->build()
        );
    }

    protected function modelClass(): string
    {
        return ClientModel::class;
    }

    protected function fields(): array
    {
        return [
            FieldBuilder::create('id', Type::nonNull(Type::id()))->build(),
            FieldBuilder::create('resellerId', Type::id())->build(),
            FieldBuilder::create('name', Type::string())->build(),
            FieldBuilder::create('creditCents', Type::int())->build(),
        ];
    }
}
```

`ClientInputBase` has the same shape over `InputObjectType`, named `ClientInput`,
with the key field nullable (absent means create).

### 5.3 Once-only subclass

`class ClientType extends ClientTypeBase {}` and
`class ClientInput extends ClientInputBase {}`, each with a short comment naming the
override points: `authorize`, `beforeWrite`, `newModel`, the three resolvers, and
`fields()` with `array_merge(parent::fields(), [...])` for computed fields.

Constructor dependencies (for example a `CurrentUser`) are added by overriding the
constructor in the subclass and calling `parent::__construct()`.

### 5.4 Type mapping

Read with `Anorm\Schema\PropertyType`, which consults a typed property and then the
`@var` docblock.

| Declared PHP type | GraphQL type |
|---|---|
| the key property | `ID!` on the Type, `ID` on the Input |
| property name ending in `Id` | `ID` |
| `int` | `Int` |
| `float` | `Float` |
| `bool` | `Boolean` |
| `string`, anything else, or undeclared | `String` |

*(revised)* A field is a key of the model's mapper map that is also a property of the
model, excluding: a property registered as a relationship, a property declared
`array`, and a property whose declared type is an Anorm model (given fully qualified,
or as a short name in the model's own namespace). The key is always listed first.

Rows are in precedence order: the key rule wins, then the `Id`-suffix rule (case
sensitive, so `resellerId` but not `paid`), then the declared type. Everything except the
Type's key is nullable. Properties prefixed `_` are skipped. Dates stay `String`, as
in the existing hand-written Types.

### 5.5 Skipped models

A model is skipped, with a printed reason, when:

- `ModelLocator` cannot load or construct it (its existing behaviour), or
- it has no single key property, that is, `mapper()->modelPrimaryKey` is empty or is
  not a property of the model.

Several FrontAccounting tables have composite keys (`0_debtor_trans` is
`type` + `trans_no`). Their Types are hand-written, extending `ModelType` directly
or not at all. Generating for composite keys is a possible later version.

## 6. CLI

```
anorm-graphql make [options]

  -m, --models       Models folder                     (default src/Models/)
  -n, --namespace    Namespace of the models           (default App\Models)
  -o, --output       Folder for generated Types        (default src/GraphQL/Type/)
  -t, --type-ns      Namespace for generated Types     (default App\GraphQL\Type)
      --tests        Folder for generated tests        (default tests/GraphQL/; 'none' to skip)
      --test-ns      Namespace for generated tests     (default Tests\GraphQL)
  -s, --schema       Path to ApiSchema.php             (default src/GraphQL/ApiSchema.php; 'none' to skip)
      --schema-ns    Namespace when scaffolding a new ApiSchema  (default App\GraphQL)
  -c, --classsuffix  Model suffix to strip             (default Model)
      --only         Comma-separated model names to include
      --readonly     Comma-separated model names to emit without Input or mutations
  -f, --force        Also overwrite the once-only files
      --dry-run      Show what would change; write nothing
  -h, --help
      --version
```

Option names, short flags and `--option=value` handling mirror `anorm make`. The
`--option=value` splitting reuses `Anorm\Tools\CliOptions::splitAssignments`.

### 6.1 A run

1. `ModelLocator->locate($models, $namespace)`, constructing each model with a
   `NullPdo`.
2. `TypeInfoBuilder` turns each located model into a `TypeInfo`: entity name (class
   short name minus the suffix), model class, fields with their GraphQL types, key
   property, read-only flag. `--only` and `--readonly` are applied here; a name in
   either list that matches no model is an error (exit 2) rather than silently
   ignored.
3. Writers emit the files in 5.1.
4. `SchemaEditor` updates `ApiSchema.php` (section 7).
5. A summary prints one line per file *(revised)*: `written`, `current` (a generated
   file already up to date), `kept`, `forced`, `refused`, `updated` (the schema),
   `skipped` with reason, or `orphaned`.

Exit codes: 0 on success, 2 on bad arguments or an unknown command, matching `anorm`.

### 6.2 `NullPdo`

```php
class NullPdo extends \PDO
{
    public function __construct() {}

    #[\ReturnTypeWillChange]
    public function setAttribute($attribute, $value) { return true; }
}
```

*(revised)* `Anorm\Model::__construct` sets the error mode on the PDO it is given, so
the stub must accept `setAttribute`. It is untyped, with the attribute, so that one
declaration is valid on PHP 7.4 and 8.x.

Generation needs each model's key property, which lives on the instance
(`$model->mapper()->modelPrimaryKey`), and `ModelLocator` constructs models with a
`\PDO`. `DataMapper`'s constructor only stores the PDO and never queries, so the stub above
suffices and generation needs no database connection or PDO driver. A model whose
constructor does query will throw; `ModelLocator` already reports that as a skip.

### 6.3 Safety properties

- Every `*Base.php` begins with the `// GENERATED by anorm-graphql` header. Before
  overwriting a base file the generator checks the existing file has that header, and
  refuses if it does not. A hand-written file at that path is never clobbered.
- `--force` affects only the once-only files, and the summary names each one it
  overwrote.
- A model removed since the last run leaves orphaned files. They are reported and
  not deleted.
- `--dry-run` writes nothing, lists the files it would write, and prints a unified
  diff for `ApiSchema.php`.

## 7. `ApiSchema.php` maintenance

This is the only place the tool edits hand-written code, and is tested accordingly
(section 8.1).

### 7.1 Behaviour

**File absent.** It is scaffolded once: a `Schema` subclass in `--schema-ns` taking a
`Container`, with empty Query and Mutation `fields` arrays and the private
`type(string $name)` helper that returns `$this->context->get($name)`. It then
belongs to the project and is edited in place like any other.

**File present.** The generator edits only entries it owns. An owned entry is one
immediately preceded by the marker comment:

```php
                    // anorm-graphql
                    GraphQLUtils::createListField('clientDelete', $this->type(ClientType::class), 'resolveDelete')
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
```

The three generated entries per entity:

```php
// Query
GraphQLUtils::createListField('clientList', $this->type(ClientType::class), 'resolveList')
    ->addArgument('query', $this->type(MangoInput::class))
    ->build(),

// Mutation
GraphQLUtils::createListField('clientDelete', $this->type(ClientType::class), 'resolveDelete')
    ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
    ->build(),
GraphQLUtils::createListField('clientUpsert', $this->type(ClientType::class), 'resolveUpsert')
    ->addArgument('input', Type::nonNull(Type::listOf(Type::nonNull($this->type(ClientInput::class)))))
    ->build(),
```

Rules:

- **Placement.** A new entry goes immediately before the first existing entry whose
  field name sorts after it, or at the end. An alphabetical file stays alphabetical.
- **Hand-written entries are never moved or reordered**, even in a file that is not
  sorted. The generator places relative to what is there.
- **Owned entries are rewritten every run**, so a change to the generated shape
  propagates. Removing the marker hands the entry to the project; it is then left
  alone.
- **Name collisions.** If an unmarked entry already defines a field name, that field
  is skipped and the summary says so. This makes a run against an existing schema
  safe.
- **Imports.** Missing `use` lines for the Type, the Input, `GraphQLUtils`,
  `MangoInput` and `GraphQL\Type\Definition\Type` are inserted in alphabetical
  position. Imports are never removed.
- **Removed models.** Owned entries for a model that no longer exists are reported,
  not deleted, consistent with orphaned files. *(revised)* A model merely left out of
  this run by `--only` still exists: the editor is given every known entity, and
  reports none of their entries.
- **Indentation** of inserted entries copies that of the neighbouring entry.
- **Idempotent.** A second run with no model changes leaves the file byte-identical.

### 7.2 Parsing

PHP's built-in `token_get_all`. Tokens give exact bracket depth and exact string and
comment boundaries, so `FieldsArrayLocator` can reliably:

1. find the `'query'` and `'mutation'` keys, and within each the `'fields'` array
   literal;
2. split that array into entries at depth-0 commas;
3. read each entry's field name, the first string literal in the entry;
4. note whether the entry is preceded by the marker comment.

Every byte the generator does not own is copied through untouched. `nikic/php-parser`
with its format-preserving printer would also work, but is a heavy dependency for
inserting lines into two arrays.

### 7.3 Failing safe

If either `fields` array cannot be found in the expected shape (fields built by a
method call, merged arrays, a non-literal), the generator changes nothing in that
file, names the structure it was looking for, and prints the entries to paste
instead. Types and tests are still written.

## 8. Testing

### 8.1 This package's tests

| Suite | DB | Covers |
|---|---|---|
| `test/tools/` | no | `TypeInfoBuilder` type mapping against fixture models built with `NullPdo`; each Writer's output against golden files; the header guard; `--force`, `--only`, `--readonly`, `--dry-run`; skipped-model and unknown-name reporting |
| `test/tools/Schema/` | no | `SchemaEditor` against fixture input/expected pairs: absent (scaffold), empty, alphabetical, unsorted, name collision, marker removed, removed model, mixed indentation, unparseable (must change nothing); and run-twice idempotency for each |
| `test/runtime/` | no | `GraphQLUtils`; `Mapper`, including `null` staying `null`; `MangoInput` shape |
| `test/integration/` | yes | `ModelType` against MariaDB: Mango selector, limit, skip, sort; invalid selector JSON; upsert create and update; rollback when the second of three rows fails; savepoint behaviour inside an outer transaction; delete returning the deleted rows; `authorize` and `beforeWrite` firing with the right arguments |
| `test/integration/EndToEnd` | yes | Generate from fixture models into a temporary directory, load the output, build a real schema, and execute list, upsert and delete through webonyx |

Golden files show the generator emits what is expected. Only the end-to-end test
shows that what is expected is valid PHP that resolves.

`phpunit.xml` defines testsuites `tools`, `runtime` and `integration`.
Composer scripts mirror Anorm's: `test:quick` (the no-DB suites), `test`,
`test:coverage`, `test:ci`, `cs:check`, `cs:fix`, `analyze`, `quality`, `ci`.
Style is PSR-12 via phpcs; phpstan at level 5.

### 8.2 Generated tests

The runtime ships `Anorm\GraphQL\Testing\ModelTypeTestCase`, adapted from
saygoweb.com-my's `GraphQLCudTestCase` to the list / upsert / delete surface.

A generated test is configuration only:

```php
class ClientTypeTest extends TestCase   // the project's tests/GraphQL/TestCase.php
{
    protected function typeClass(): string { return ClientType::class; }
    protected function entityName(): string { return 'client'; }
    protected function isReadOnly(): bool { return false; }
    protected function expectedFieldTypes(): array
    {
        return ['id' => 'ID!', 'resellerId' => 'ID', 'name' => 'String', 'creditCents' => 'Int'];
    }
    protected function sampleInput(): array { return ['name' => 'name 1', 'creditCents' => 1]; }
    protected function sampleUpdate(): array { return ['name' => 'name 2']; }
}
```

The base case runs, per entity:

- **Structural, no DB.** The Type's name; every expected field present with the
  expected GraphQL type; the Input's fields matching the Type's.
- **Lifecycle, DB.** List is empty → upsert two rows → list returns two → a Mango
  selector on the key returns exactly one → upsert with `id` updates in place →
  delete returns the row → list no longer contains it.
- Read-only entities get the structural tests and a list test.

Decisions:

1. **The project supplies the wiring.** `ModelTypeTestCase` declares
   `createContainer(): Container` and `createSchema(Container $c): Schema` as
   abstract. The generator scaffolds `tests/GraphQL/TestCase.php` once, implementing
   both with clearly marked placeholders; it is then the project's file. Each project
   bootstraps its container differently, and a guess would be wrong somewhere.
2. **Cleanup is by rollback, not truncation.** `setUp` begins a transaction on the
   container's PDO and `tearDown` rolls it back. `GraphQLCudTestCase` truncates
   tables, which is dangerous if a test configuration ever points at a real
   FrontAccounting company database. This is why `ModelType` uses savepoints (4.3).
3. **Sample data is a starting point.** Values derive from the GraphQL type
   (`'<name> 1'`, `1`, `1.5`, `true`). `ID`-typed foreign keys are left out of
   `sampleInput`, because the generator cannot know a valid parent row. A test for an
   entity with a required foreign key fails until the project fills it in; the
   generated file says so in a comment at that spot.

## 9. Docker tooling

Ported from Anorm's `docker/`, which suits this repo unchanged in design: a library
has nothing to serve, so the app container idles and every command arrives by exec.

```
docker/
  anorm-graphql         driver script, ported from docker/anorm
  docker-compose.yml    app (php-cli, sleep infinity), db (mariadb), phpmyadmin [tools profile]
  Dockerfile            php:${PHP_VARIANT}, pdo_mysql, pinned Xdebug on 7.4, host UID/GID remap
  php.ini
  xdebug.ini
  scripts/entrypoint.sh composer install on first start
  README.md
```

Commands match Anorm's: `init`, `up [--build] [--tools]`, `down [-v]`,
`test [args]`, `quality`, `coverage`, `ci`, `shell`, `mysql`, `db-reset`, `info`,
`composer`, `help`.

Differences from Anorm's copy, chosen so both stacks can run at once:

| | Anorm | anorm-graphql |
|---|---|---|
| Compose project name *(revised)* | `anorm`, per checkout `anorm-<dir>` | `anorm-graphql`, per checkout `agq-<dir>` |
| Test database | `anorm_test` | `anorm_graphql_test` |
| Default DB port *(revised)* | 3316 | 3319 (3317 is taken by an Anorm worktree) |
| Default phpMyAdmin port *(revised)* | 8096 | 8099 |
| `PHP_IDE_CONFIG` | `serverName=anorm` | `serverName=anorm-graphql` |

One addition: `docker/anorm-graphql make [args]` runs `bin/anorm-graphql.php make` in
the container. Generation needs no database, but it needs a PHP that can load the
models, and a host without the right PHP can use the container.

- `docker/anorm-graphql test` runs `composer test:quick`, the no-DB suites.
- `docker/anorm-graphql test --testsuite integration` and `ci` run everything.
- Compose injects `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`. A
  `TestEnvironment::connect()` helper reads the environment before any `.env`, as
  Anorm's does, so the stack's database wins.
- PHP 7.4 is the default, being the supported floor.
  `PHP_VARIANT=8.3-cli docker/anorm-graphql up --build` checks the other end.

## 10. FrontAccounting

The expected first use:

```
anorm make ...                                   # models from the FA database
anorm-graphql make \
  -m modules/graphql/src/Model   -n FA\GraphQL\Model \
  -o modules/graphql/src/Type    -t FA\GraphQL\Type \
  -s modules/graphql/src/ApiSchema.php --schema-ns FA\GraphQL \
  --readonly DebtorTrans,GlTrans,...
```

Two FrontAccounting facts shape how the output is used, not how it is generated:

- **Transactional tables must not be written directly.** Upserting a row into
  `0_debtor_trans` bypasses GL postings. Such entities are generated `--readonly`
  until the project overrides `resolveUpsert` in the once-only subclass to go through
  FrontAccounting's own functions. The generation gap exists for exactly this.
- **Composite-key tables are skipped** (5.5) and hand-written.

Table-prefix handling (`0_`) is Anorm's concern, in the models, and does not reach
this tool.

## 11. Build order

Each step is independently testable, and each leaves the repository working.

1. Repository skeleton: `composer.json`, phpunit, phpcs, phpstan, and `docker/`.
2. Runtime without a database: `GraphQLUtils`, `Mapper`, `MangoInput`.
3. `ModelType`, with its integration tests.
4. Generator core: `NullPdo`, `TypeInfo`, `TypeInfoBuilder`, the Type and Input
   writers, `TypeMaker`, and the CLI.
5. `ModelTypeTestCase`, `TestWriter`, `TestCaseWriter`.
6. `SchemaEditor`, `SchemaScaffolder`, `FieldsArrayLocator`.
7. The end-to-end test.
8. README and docs.
