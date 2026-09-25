# anorm-graphql

`saygoweb/anorm-graphql` generates the GraphQL Types, Inputs and tests for a PHP
GraphQL API from [Anorm](https://github.com/saygoweb/anorm) models, and ships the
small runtime those generated classes depend on. The full path is: database →
`anorm make` → models → `anorm-graphql make` → Types, Inputs, tests, and
`ApiSchema.php` entries.

## Install

```
composer require saygoweb/anorm-graphql
```

Supported range (see `composer.json`):

| Package | Version |
|---|---|
| `php` | `^7.4 \|\| ^8.0` |
| `saygoweb/anorm` | `^3.2.1` |
| `webonyx/graphql-php` | `^15.32.3` |
| `php-di/php-di` | `^6.0` |
| `wp-cli/php-cli-tools` | `^0.11.10` |

**Why `webonyx/graphql-php` ^15.32.3.** Composer refuses every webonyx 14.x release
over three denial-of-service advisories, fixed only in 15.32.3 and later:
GHSA-r7cg-qjjm-xhqq (unbounded parser recursion, high), GHSA-fc86-6rv6-2jpm
(quadratic `OverlappingFieldsCanBeMerged`, high), and CVE-2026-40476 (medium).
webonyx 15 still supports PHP 7.4.

**Why no `simpod/graphql-utils`.** `simpod/graphql-utils` 0.5.3 is its last release
for PHP 7.4, and it requires webonyx `^14`; 0.6 and later need PHP 8.1. Raising the
floor to PHP 8.1 was rejected, and so was staying on webonyx 14 with its open
advisories. Instead the runtime ships `Anorm\GraphQL\Builder\FieldBuilder` and
`Anorm\GraphQL\Builder\ObjectBuilder`, which offer the calls this codebase uses:

- `FieldBuilder`: `create`, `setDescription`, `addArgument`, `setResolver`,
  `setDeprecationReason`, `build`.
- `ObjectBuilder`: `create`, `setDescription`, `setFields`, `build`.

A project moving from simpod changes its `use` lines from `Simpod\GraphQLUtils\Builder\...`
to `Anorm\GraphQL\Builder\...`; the calls read the same.

**Why `saygoweb/anorm` ^3.2.1.** Up to Anorm 3.2.0, `DataMapper::read()` and the
UPDATE branch of `write()` concatenate the key value into their SQL (advisory
GHSA-xc47-9hw7-px38). 3.2.1 binds it instead. Requiring `^3.2.1` means this package
cannot be installed beside a vulnerable Anorm.

## Quick start

```
docker/anorm-graphql make -m test/Fixtures/Model -n 'Anorm\GraphQL\Test\Fixtures\Model' \
  -o build/docs/Type -t 'Api\GraphQL\Type' \
  --tests build/docs/tests --test-ns 'Tests\GraphQL' \
  -s build/docs/ApiSchema.php --schema-ns 'Api\GraphQL'
```

Installed as a dependency, the same options are given to `vendor/bin/anorm-graphql.php
make`, pointed at your own models folder, output folder and namespaces.

A real run against this repository's fixture models (`test/Fixtures/Model`, two
models: `Owner` and `Widget`, plus `LedgerLine` which has no single key) prints:

```
written  build/docs/Type/Owner/Base/OwnerTypeBase.php
written  build/docs/Type/Owner/Base/OwnerInputBase.php
written  build/docs/Type/Owner/OwnerType.php
written  build/docs/Type/Owner/OwnerInput.php
written  build/docs/tests/OwnerTypeTest.php
written  build/docs/Type/Widget/Base/WidgetTypeBase.php
written  build/docs/Type/Widget/Base/WidgetInputBase.php
written  build/docs/Type/Widget/WidgetType.php
written  build/docs/Type/Widget/WidgetInput.php
written  build/docs/tests/WidgetTypeTest.php
written  build/docs/tests/TestCase.php
written  build/docs/ApiSchema.php
skipped  Anorm\GraphQL\Test\Fixtures\Model\LedgerLineModel: no single key: 'id' is not a property of the model
```

Running the same command again, with nothing changed, prints `current`, `kept` and
`current` instead of `written`:

```
current  build/docs/Type/Owner/Base/OwnerTypeBase.php
current  build/docs/Type/Owner/Base/OwnerInputBase.php
kept     build/docs/Type/Owner/OwnerType.php
kept     build/docs/Type/Owner/OwnerInput.php
kept     build/docs/tests/OwnerTypeTest.php
current  build/docs/Type/Widget/Base/WidgetTypeBase.php
current  build/docs/Type/Widget/Base/WidgetInputBase.php
kept     build/docs/Type/Widget/WidgetType.php
kept     build/docs/Type/Widget/WidgetInput.php
kept     build/docs/tests/WidgetTypeTest.php
kept     build/docs/tests/TestCase.php
current  build/docs/ApiSchema.php
skipped  Anorm\GraphQL\Test\Fixtures\Model\LedgerLineModel: no single key: 'id' is not a property of the model
```

## What you get

The GraphQL surface below is the real output of `GraphQL\Utils\SchemaPrinter::doPrint($schema)`
against the schema generated for the fixtures above (`Owner` read/write, `Widget`
read/write):

```graphql
type Query {
  ownerList(query: MangoInput): [OwnerType!]!
  widgetList(query: MangoInput): [WidgetType!]!
}

input MangoInput {
  action: String
  selector: String
  limit: Int
  skip: Int
  sort: [String]
}

type OwnerType {
  id: ID!
  name: String
}

type WidgetType {
  id: ID!
  name: String
  quantity: Int
  price: Float
  active: Boolean
  ownerId: ID
  notes: String
}

type Mutation {
  ownerDelete(id: [ID!]!): [OwnerType!]!
  ownerUpsert(input: [OwnerInput!]!): [OwnerType!]!
  widgetDelete(id: [ID!]!): [WidgetType!]!
  widgetUpsert(input: [WidgetInput!]!): [WidgetType!]!
}

input OwnerInput {
  id: ID
  name: String
}

input WidgetInput {
  id: ID
  name: String
  quantity: Int
  price: Float
  active: Boolean
  ownerId: ID
  notes: String
}
```

- `widgetList` with no `query` returns every row.
- `widgetUpsert` creates a row when its input has no `id`, and updates when it has
  one. It returns the written rows.
- `widgetDelete` returns the rows as they were immediately before deletion.
- A read-only entity (see `--readonly` below) gets `widgetList` only, and no
  `WidgetInput`.

Per entity, these files are written:

| File | Written |
|---|---|
| `<output>/Widget/Base/WidgetTypeBase.php` | every run — **regenerated; do not edit** |
| `<output>/Widget/Base/WidgetInputBase.php` | every run; not if read-only — **regenerated; do not edit** |
| `<output>/Widget/WidgetType.php` | only if absent, or `--force` — **yours** |
| `<output>/Widget/WidgetInput.php` | only if absent, or `--force`; not if read-only — **yours** |
| `<tests>/WidgetTypeTest.php` | only if absent, or `--force` — **yours** |

Once per project, only if absent: `<tests>/TestCase.php` — **yours**.

`<Entity>TypeBase` extends `Anorm\GraphQL\ModelType`, or the class given to `--type-base`.

With `--mutations create-update`, in place of the two `WidgetInput*` rows above:

| File | Written |
|---|---|
| `<output>/Widget/Base/WidgetCreateInputBase.php` | every run — **regenerated; do not edit** |
| `<output>/Widget/Base/WidgetUpdateInputBase.php` | every run — **regenerated; do not edit** |
| `<output>/Widget/WidgetCreateInput.php` | only if absent, or `--force` — **yours** |
| `<output>/Widget/WidgetUpdateInput.php` | only if absent, or `--force` — **yours** |

### Dates

A property declared `\DateTimeInterface`, `\DateTimeImmutable`, or an `@var`
docblock naming any class implementing `\DateTimeInterface` (including `\DateTime`,
as a docblock only — see below), becomes a `Date` field (`YYYY-MM-DD`), shared
through `Anorm\GraphQL\Type\DateType::instance()` — one instance per process, never
through the container. MySQL's zero date (`0000-00-00`) reads back as `null`, whether
it arrives as the raw string or, once a model applies a date transformer, as the
`\DateTime` it rolls over to (`-0001-11-30`). Give the model a date transformer so
Anorm hands it a `\DateTime` and writes it back as `Y-m-d`:

```php
$this->mapper()->transformers['due_on'] = new \Anorm\Transform\SqlDateTimeTransform('Y-m-d');
```

**A natively typed `\DateTime` property is the one exception**: `parseValue()` /
`parseLiteral()` always hand back a `\DateTimeImmutable`, and PHP enforces a native
property type at assignment, so `public \DateTime $dueOn;` would throw a `TypeError`
on every create or update. The generator leaves such a property as `String` rather
than generate code that fails at runtime. Declare it `\DateTimeInterface` or
`\DateTimeImmutable` instead (typed or `@var`), or leave the property untyped with
an `@var \DateTime` docblock (not enforced by PHP, so safe) if the model must keep
assigning a `\DateTime`.

The generator never reads the column, only the declared PHP type: declare a
datetime property (one with a time component) as `string` to keep it a `String`
— `Date` only ever prints and parses the day.

## Options

From `bin/anorm-graphql.php --help`:

```
anorm-graphql: GraphQL Types from Anorm models

Usage: anorm-graphql make [options]

Flags
  --help, -h   Display this help
  --version    Display the version
  --force, -f  Also overwrite the once-only files
  --dry-run    Show what would change; write nothing

Options
  --models, -m       Models folder [default: src/Models/]
  --namespace, -n    Namespace of the models [default: App\Models]
  --output, -o       Folder for generated Types [default: src/GraphQL/Type/]
  --type-ns, -t      Namespace for generated Types [default: App\GraphQL\Type]
  --tests            Folder for generated tests; 'none' to skip [default: tests/GraphQL/]
  --test-ns          Namespace for generated tests [default: Tests\GraphQL]
  --schema, -s       Path to ApiSchema.php; 'none' to skip [default: src/GraphQL/ApiSchema.php]
  --schema-ns        Namespace when scaffolding a new ApiSchema [default: App\GraphQL]
  --classsuffix, -c  Model suffix to strip [default: Model]
  --type-base        Class every generated TypeBase extends [default: Anorm\GraphQL\ModelType]
  --mutations        upsert, or create-update for separate mutations [default: upsert]
  --only             Comma-separated model names to include
  --readonly         Comma-separated models to emit without Input or mutations
  --input-only       Comma-separated models to emit as Input only
```

`--type-base` names a class of your own for every generated `<Entity>TypeBase` to
extend, in place of `Anorm\GraphQL\ModelType`. It must extend `ModelType`, must not be
final, and must be loadable by your project's autoloader when the generator runs
(run it from your project's `vendor/bin`). Anything else is exit 2 before a file is
written:

    Error: --type-base 'App\GraphQL\Missing' cannot be loaded: it is not a class the autoloader can find

See `docs/customising.md`, "A project base class via `--type-base`".

`--mutations create-update` gives each writable entity `<entity>Create` and
`<entity>Update` in place of `<entity>Upsert`, with two Inputs: `<Entity>CreateInput`
(no key; a property whose docblock says `@required` is non-null) and
`<Entity>UpdateInput` (the key is `ID!`; everything else optional, and a field left
out is left as it was). Choose it when creating and changing a row differ enough —
required fields, rules that only apply to an existing row — that one Input would
hide it. Switching an existing project reports the old `<Entity>Input` files and
`<entity>Upsert` entries as orphaned; nothing is deleted.

`--input-only <names>` writes only the Input(s) for those entities, with no schema
entries: for rows that are only ever written as part of another entity's input, such
as an order's lines. Add `--readonly` for the same names to have their read-only Type
and `<entity>List` too.

An unknown argument is exit 2, and its default is never used silently:

```
$ anorm-graphql make -m test/Fixtures/Model -n 'Anorm\GraphQL\Test\Fixtures\Model' --outut build/docs/bad -s none --tests none
anorm-graphql: GraphQL Types from Anorm models
Error: Unexpected argument '--outut', try '--help'
```
(exit code 2)

A name given to `--only`, `--readonly` or `--input-only` that matches no model is
the same kind of error:

```
Error: --only names 'Nope', which is not a model in test/Fixtures/Model
```
(exit code 2)

`--dry-run` writes nothing and prints a unified diff for `ApiSchema.php` when it
would change, for example after adding a model:

```
--- build/docs/collide/ApiSchema.php
+++ build/docs/collide/ApiSchema.php (new)
@@ -4,6 +4,8 @@

 use Anorm\GraphQL\GraphQLUtils;
 use Anorm\GraphQL\Type\MangoInput;
+use Api\GraphQL\Type\Gadget\GadgetInput;
+use Api\GraphQL\Type\Gadget\GadgetType;
 use Api\GraphQL\Type\Owner\OwnerInput;
 use Api\GraphQL\Type\Owner\OwnerType;
 use Api\GraphQL\Type\Widget\WidgetInput;
@@ -31,6 +33,10 @@
             'query' => new ObjectType([
                 'name' => 'Query',
                 'fields' => [
+                    // anorm-graphql
+                    GraphQLUtils::createListField('gadgetList', $this->type(GadgetType::class), 'resolveList')
+                        ->addArgument('query', $this->type(MangoInput::class))
+                        ->build(),
                     GraphQLUtils::createListField('ownerList', $this->type(OwnerType::class), 'resolveList')
                         ->addArgument('query', $this->type(MangoInput::class))
                         ->build(),
```

## Your `ApiSchema.php`

`ApiSchema.php` is the one file the generator edits in place. If it is absent it is
scaffolded once: a `Schema` subclass in `--schema-ns` taking a `Container`, with
empty Query and Mutation `fields` arrays and a private `type(string $name)` helper
that returns `$this->context->get($name)`. From then on it is your file, edited in
place.

An entry the generator owns is one immediately preceded by the `// anorm-graphql`
marker comment:

```php
                'fields' => [
                    // anorm-graphql
                    GraphQLUtils::createListField('widgetList', $this->type(WidgetType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
```

- **Owned means directly led by the marker.** If anything but whitespace sits
  between the marker and the entry — a comment you added, say — the entry is
  treated as hand-written from then on.
- **Placement is alphabetical**, by field name: a new entry goes immediately
  before the first existing entry whose name sorts after it, or at the end.
- **Hand-written entries are never moved or reordered**, even in a file that
  is not sorted overall. The generator places relative to what is there.
- **To take an entry over**, delete its `// anorm-graphql` line. It is then
  left alone on every later run.
- **Collisions.** If an unmarked (hand-written) entry already defines a field
  name the generator would write, that field is skipped and reported:
  `collision: 'ownerList' already exists and is not marked as generated; left alone`.
  This makes a run against an existing, partly hand-written schema safe.
- **Orphans.** A marked entry whose model no longer exists is reported, never
  deleted: `orphaned: 'ownerDelete' is marked as generated but no model produces it`.
  The same applies to the orphaned `*Base.php` files:
  `orphaned build/docs/collide/Type/Owner/Base/OwnerTypeBase.php (no model produces it; not deleted)`.
  A model merely left out of a run by `--only` still counts as existing, so its
  entries are not reported.
- **Nothing is ever deleted.**

Two rules override everything else:

1. **Never write a file that does not compile, and never change what a
   hand-written reference means.** A class is imported only when its short
   name is free in the file: bound by no import, declared by no class in the
   file, and used unqualified nowhere in it. Otherwise the generated entry
   names the class in full (`\GraphQL\Type\Definition\Type::nonNull(...)`),
   which always works. If the imports cannot be read with confidence (an
   unusual `use`, a braced namespace, several namespaces in one file) nothing
   is imported and everything is fully qualified.
2. **When unsure, change nothing.**

The generator only recognises a fields array reached exactly as
`'query' => new ObjectType([ ... 'fields' => [ ... ] ])` (or `'mutation' => ...`),
written one entry per line with the closing bracket on a line of its own. When the
file is not in that shape — fields built by a method call, a closure, `array()`,
merged arrays, entries sharing a line, or an ambiguous match — the generator changes
nothing in that file and prints the entries to paste by hand, fully qualified and
grouped by the array they belong in:

```
ApiSchema not changed: 'fields' under 'query' is not a literal [ ... ] array
Add these entries to build/docs/badschema/ApiSchema.php by hand, in alphabetical order:
in the 'query' fields array:
// anorm-graphql
\Anorm\GraphQL\GraphQLUtils::createListField('ownerList', $this->type(\Api\GraphQL\Type\Owner\OwnerType::class), 'resolveList')
    ->addArgument('query', $this->type(\Anorm\GraphQL\Type\MangoInput::class))
    ->build(),
in the 'mutation' fields array:
// anorm-graphql
\Anorm\GraphQL\GraphQLUtils::createListField('ownerDelete', $this->type(\Api\GraphQL\Type\Owner\OwnerType::class), 'resolveDelete')
    ->addArgument('id', \GraphQL\Type\Definition\Type::nonNull(\GraphQL\Type\Definition\Type::listOf(\GraphQL\Type\Definition\Type::nonNull(\GraphQL\Type\Definition\Type::id()))))
    ->build(),
```

Types and tests are still written even when the schema itself could not be edited.

## Generated tests

`tests/GraphQL/TestCase.php` is scaffolded once and is yours: it implements
`createContainer(): Container` and `createSchema(Container $c): Schema`, the two
things every project bootstraps differently. Every generated `<Entity>TypeTest`
extends it, and its actual test methods are inherited from
`Anorm\GraphQL\Testing\ModelTypeTestCase`: structural tests with no database (the
Type's fields, the Input mirroring the Type), and, against a database, a lifecycle
test (list is empty → upsert two rows → list returns two → a Mango selector on the
key returns exactly one → upsert with `id` updates in place → delete returns the row
→ list no longer contains it).

- **Rollback, not truncation.** The lifecycle test begins a transaction on the
  container's PDO and `tearDown` rolls it back. Nothing is ever truncated.
- **A table that cannot roll back is not written to.** A rollback only cleans up where
  the table's storage engine has transactions. On a MyISAM table, as older applications
  often have, it does nothing, and a test's rows would stay in the database for good.
  So before it writes, the lifecycle test asks MySQL or MariaDB whether the Type's
  table has transactions, and if not it skips itself and says why. Convert the table
  to InnoDB in the test database, or override `allowNonTransactionalTables()` to return
  `true` where leaving rows behind is acceptable. The same holds for tests you add
  yourself: any mutation run through `execute()` or `upsert()` first begins the
  clean-up transaction and makes this check, so a write of your own is rolled back, or
  the test is skipped, just as the inherited ones are. What it cannot cover is a write
  that does not go through them: a model you `write()` directly, or a statement you run
  on the PDO yourself. Call `useDatabase()` before those, and keep them to tables that
  can roll back. The check covers the Type's own table only: a parent row you create
  in `sampleInput()` in some other table is yours to look after, and on another
  database the check is not made at all.
- **The models must use the container's PDO.** Cleanup rolls back
  `$container->get(\PDO::class)`. A model that connects some other way writes outside
  that transaction.
- **Foreign keys are left out of `sampleInput()` on purpose.** The generator cannot
  know a valid parent row. The generated file says so in a comment at that spot:

  ```php
  protected function sampleInput(): array
  {
      // Foreign keys are left out: the generator cannot know a valid parent row.
      // If any is required, create the parent here and add: ownerId
      return [
          'name' => 'name 1',
          ...
      ];
  }
  ```
- **An empty `sampleUpdate()` is reported, not passed off as tested.** When there is
  nothing sensible to change (or you clear it), the lifecycle test still exercises
  create, view and delete, then calls `markTestIncomplete('sampleUpdate() is empty,
  so updating was not exercised')` instead of passing in silence.

## Safety

- **Base files are overwritten only if the existing file *begins* with the
  generated header**, as the first thing after the opening tag. A file that merely
  quotes the header somewhere else is somebody's own:
  `refused  .../Base/WidgetTypeBase.php (exists and does not begin with the generated header; move it aside to regenerate)`.
- **Writes never leave the directories given**, and never follow a symbolic link:
  `refused  .../Widget/WidgetType.php (is a symbolic link)`.
- **An unknown argument is exit 2.** A misspelt `--output` never becomes the
  default folder (see Options above).
- **An entity's files are all parsed before any of them is written.** An entity
  that fails to parse is written whole or not at all, and gets no schema entries
  either.
- `--force` affects only the once-only files (`<Entity>Type.php`,
  `<Entity>Input.php`, `<Entity>TypeTest.php`), and the summary names each one it
  overwrote.
- Nothing is ever deleted. A model removed since the last run leaves orphaned
  files and schema entries, reported, not removed (see above).

## What gets skipped, and why

Run against `test/Fixtures/AwkwardModel` (`Anorm\GraphQL\Test\Fixtures\AwkwardModel`):

```
skipped  Anorm\GraphQL\Test\Fixtures\AwkwardModel\GadgetModel: entity 'Gadget' is also produced by Anorm\GraphQL\Test\Fixtures\AwkwardModel\Gadget; rename one of the models
skipped  Anorm\GraphQL\Test\Fixtures\AwkwardModel\Gadget: entity 'Gadget' is also produced by Anorm\GraphQL\Test\Fixtures\AwkwardModel\GadgetModel; rename one of the models
skipped  Anorm\GraphQL\Test\Fixtures\AwkwardModel\ListModel: entity 'List' is not a name PHP 7.4 allows in a namespace; rename the model
```

And against `test/Fixtures/Model`:

```
skipped  Anorm\GraphQL\Test\Fixtures\Model\LedgerLineModel: no single key: 'id' is not a property of the model
```

A model is skipped, with a printed reason, when:

- `ModelLocator` cannot load or construct it.
- **It has no single key property** — `mapper()->modelPrimaryKey` is empty, or is
  not a property of the model (`LedgerLineModel` above: its key is the composite
  `type` + `transNo`).
- **Two models produce the same entity name.** `Gadget` and `GadgetModel` collide
  because the `Model` suffix is optional; so do two short names in different
  namespaces. Both are skipped, each naming the other.
- **The entity name is a word PHP 7.4 refuses as a namespace segment** — the 68
  words PHP 7.4.33 was measured to refuse (`list`, `class`, `default`, ...; see
  `Anorm\GraphQL\Tools\Writer\Php::RESERVED`). Words reserved only as a class or
  type name — `Parent`, `Match`, `Object`, `String` — are fine, because an entity
  is never used as a bare class name (its classes are `ParentType`, `ParentInput`,
  and so on). Any reserved word is fine as a *property* name.
- The code generated for it would not parse.

## Filtering with Mango

Every `List` field takes an optional `query: MangoInput`:

```graphql
input MangoInput {
  action: String
  selector: String   # JSON-encoded Mango selector
  limit: Int
  skip: Int
  sort: [String]
}
```

`selector` is a JSON string (see [cloudant/mango](https://github.com/cloudant/mango)),
decoded and passed to Anorm's `MangoQueryParser`. Two rules, enforced by
`Anorm\GraphQL\ModelType` before the query ever reaches the parser
(see `src/ModelType.php`):

1. **Every field name in a selector, and every `sort` field, must be a property of
   the model.** This is a security boundary, not a nicety: Anorm's Mango parser
   puts a name it does not recognise into the SQL between backticks, unescaped, and
   these names arrive from the API's clients. An unknown name is refused:
   `Argument 'query' names an unknown field 'nope'`.
2. **A field whose name Anorm's Mango parser reads as an operator cannot be used in
   a selector or a sort.** The parser reads these words as operators regardless of
   context:

   ```
   and, or, not, nor, eq, ne, gt, gte, lt, lte, in, nin,
   exists, type, regex, beginswith, all, elemmatch, allmatch, size
   ```

   A property called `type` — a common discriminator column name — cannot be
   filtered or sorted on:
   `Argument 'query' cannot filter or sort on 'type': Anorm's Mango parser reads that word as an operator`.
   Use `scope()` to fix a `type` discriminator to a constant value instead (see
   `docs/customising.md`); a scope condition is applied as a bound SQL `WHERE`, not
   through the Mango selector, so it is not subject to this restriction.

Invalid selector JSON, an invalid sort, or any other malformed `query` argument is
a client-safe `GraphQL\Error\UserError`, never an internal server error.

## Limitations

- **No relationship fields.** A model's `belongsTo` / `hasMany` relationships are
  not generated as nested GraphQL fields.
- **A model-typed property is recognised only three ways**: as a relationship
  (excluded from the fields entirely), by a fully qualified `@var`, or by a short
  name in the model's own namespace. Anything else is treated as a plain column.
- **An all-read-only schema has an empty `Mutation` type.** If every entity in a
  run is read-only (or `--readonly`), the generated `Mutation` array has no
  entries. Most GraphQL validators reject a schema with an empty object type;
  remove `'mutation'` from such a schema by hand, or make sure at least one entity
  is writable.
- **Mutations refuse a model in Anorm's dynamic mode.** Dynamic mode runs DDL
  during a write, and DDL commits implicitly in MySQL, which would silently commit
  both the rows before it and the caller's own transaction. `resolveUpsert` and
  `resolveDelete` throw a `LogicException` for such a model.
- **`MangoInput.action` does nothing.** The field is part of the input shape carried over
  from earlier projects. `ModelType` does not read it, and a value sent in it is ignored.
- **The command line prints errors to standard output**, as Anorm's does, not to standard
  error. Rely on the exit code (0, or 2 for a bad argument), not on which stream a message
  arrives on.
- **Composite keys are not supported.** A table with a composite key (see
  `LedgerLineModel` above) is skipped; hand-write its Type. A table with a *fixed
  discriminator plus a single key* — several kinds of row told apart by one column,
  each kind unique on the key within itself — is supported, through `scope()` (see
  `docs/customising.md`).

## Developing

```
docker/anorm-graphql up                        # start PHP 7.4 + MariaDB
docker/anorm-graphql test                      # composer test:quick — no-DB suites
docker/anorm-graphql test --testsuite integration   # the database suite too
docker/anorm-graphql ci                        # the full CI run: tests with clover + phpcs + phpstan
```

To check the other end of the supported PHP range:

```
PHP_VARIANT=8.3-cli docker/anorm-graphql up --build
```

This rebuilds the same checkout's container against PHP 8.3 rather than the 7.4
floor; `vendor/` is shared, so run `docker/anorm-graphql composer install` afterwards
if a dependency resolves differently.

See `docs/customising.md` for the once-only subclass override points.
