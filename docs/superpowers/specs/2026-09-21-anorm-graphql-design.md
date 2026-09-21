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
    Schema/Tokens.php               (revised) tokens, offsets, depth, brackets; same on 7.4 and 8.x
    Schema/ImportTable.php          (revised) what `use` binds; import, or fully qualify
    Schema/SchemaShapeException.php (revised) "not a shape I am sure of"
    Schema/FieldsArray.php
    Schema/FieldsEntry.php
    Schema/SchemaEditResult.php
  test/
    TestEnvironment.php, TempDir.php, SchemaProbe.php    shared by the suites (revised)
    tools/          no database
    runtime/        no database
    integration/    database
    Fixtures/       capitalised: its classes are PSR-4 autoloaded (revised)
  docker/
  docs/
```

`composer.json`:

- `require` *(revised)*: `php: ^7.4 || ^8.0`, `saygoweb/anorm: ^3.2.1` (3.2.1 binds the key
  in `read()` and `write()`, advisory GHSA-xc47-9hw7-px38; requiring it means this
  package cannot be installed beside a vulnerable Anorm),
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

`Anorm\Schema\PropertyType` and `Anorm\Tools\ModelLocator`, which the generator relies
on, have both been there since `v3.2.0`.

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
protected function scope(): array;                        // (revised) default []

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

**No client value is ever concatenated into SQL** *(revised after Gate A)*. Up to
3.2.0, Anorm's `DataMapper::read()` and the UPDATE branch of `write()` concatenate the
key value into their SQL (private advisory GHSA-xc47-9hw7-px38). `ModelType` therefore
never calls `Model::read()` / `readOrThrow()`. A single private `scopedQuery()` builds
every query, for lists and for reads by key alike, and binds every value. On update the
key is never copied from the input onto the model, so `write()` only ever sees the key
the database returned. A numeric key must equal the requested id exactly as a string:
MySQL would otherwise cast a bound `"5 anything"` to row 5.

**`scope()`** *(revised; user-approved addition)*. Fixed property => value pairs that
every row of the Type has, for a table holding several kinds of row told apart by a
discriminator column (FrontAccounting's `sales_orders.trans_type`, `debtor_trans.type`).
The scope is ANDed into every list and every read by key, stamped on every write, and an
input that sets a scope property to another value is a client-safe error. It is applied
as bound WHERE conditions, not through the Mango selector, because a discriminator is
usually named `type`, which Anorm's Mango parser reads as an operator. Within the scope
the model's key must be unique. Generating scoped entities from a config file is v1.1.

**`resolveList`**

1. `authorize('list', null, $context)`.
2. Build a Mango array from `$args['query']`, JSON-decoding `selector`. Invalid JSON
   throws a client-safe error naming the argument.
3. *(revised)* **Whitelist every field name.** Each selector key at any depth that is
   not a `$operator`, and each `sort` field, must be a key of the model's property
   map; anything else throws a client-safe error. This is a security boundary:
   Anorm's Mango parser puts a name it does not recognise into the SQL between
   backticks, unescaped, and these names arrive from the API's clients.
   *(revised after Gate A)* The selector is decoded as objects for this check, because
   `json_decode(..., true)` turns a key such as `"2"` into an integer indistinguishable
   from a list position. A field whose name Anorm's parser reads as an operator (`type`,
   `size`, `in`, `and`, ...) cannot appear in a selector or a sort, and is refused with
   the reason. `ModelType` drives `MangoQueryParser` itself, so every parser error
   becomes a client-safe error instead of "Internal server error".
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
and rolling back to it on failure. *(revised after Gate A)* A rollback that itself fails
never replaces the exception that caused it. Mutations refuse a model in Anorm's dynamic
mode with a `LogicException`: dynamic mode runs DDL during a write, DDL commits
implicitly in MySQL, and that would silently commit both the rows before it and the
caller's own transaction. If DDL runs anyway (from a hook, say) and the mutation then
succeeds, the release or commit fails because the transaction has already ended; that,
and only that (MySQL error 1305, or PHP 8's "no active transaction"), is reported as an
implicit commit, with the driver error kept as the cause. Any other failure to release or
commit, such as a lost connection, is rethrown untouched: the server has rolled the work
back, and saying otherwise could make a caller skip a retry. Known limit: with no outer
transaction, PHP 7.4's PDO cannot tell that its transaction has gone, so there an
implicit commit goes unreported. This is required so the generated tests can wrap
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

A model is skipped, with a printed reason, when any of these holds. *(revised after
Gate C: the last three.)*

- `ModelLocator` cannot load or construct it (its existing behaviour), or
- it has no single key property, that is, `mapper()->modelPrimaryKey` is empty or is
  not a property of the model;
- another model produces the same entity name (`Gadget` and `GadgetModel`, since the
  suffix is optional, or one short name in two namespaces). Both are skipped, each
  naming the other: one set of files cannot belong to two models;
- its entity name is a word PHP 7.4 refuses as a namespace segment (`List`, `Class`,
  `Default` ...). The list is the 68 words PHP 7.4.33 was measured to refuse. Words
  reserved only as class or type names (`Parent`, `Object`, `String`, `Match`) are
  fine, since an entity is never a bare class name; and any reserved word is fine as a
  property name;
- the code generated for it would not parse. All of an entity's files are parsed before
  any is written, so an entity is written whole or not at all, and one that is not
  written gets no schema entries either.

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
  overwriting a base file the generator checks that the existing file **begins** with
  that header, as the first thing after the opening tag, and refuses if it does not.
  *(revised after Gate C: a file that merely quotes the header somewhere is
  somebody's own, and the first implementation clobbered one.)*
- *(revised after Gate C)* The generator writes only inside the directories it was
  given (the output folder, the tests folder, the schema file's folder) and never
  through a symbolic link. A path whose real location is elsewhere is refused.
- *(revised after Gate C)* An argument that is neither the command nor a known option
  is exit 2. A misspelt `--output` must not quietly become the default folder.
- *(revised after Gate C)* `--type-ns`, `--test-ns` and `--schema-ns` containing a
  segment PHP 7.4 refuses are exit 2, writing nothing.
- `--force` affects only the once-only files, and the summary names each one it
  overwrote.
- A model removed since the last run leaves orphaned files. They are reported and
  not deleted. *(revised after Gate C)* So are the files and the schema entries of an
  entity that is now skipped for good, and the Input files of an entity that has
  become read-only, and this holds even when no entity of the run can be generated.
- `--dry-run` writes nothing, lists the files it would write, and prints a unified
  diff for `ApiSchema.php`.

## 7. `ApiSchema.php` maintenance

This is the only place the tool edits hand-written code, and is tested accordingly
(section 8.1).

*(revised after Gate B)* The first implementation was reviewed adversarially and did not
survive: it scanned freely for `'query' =>`, compared imports by class rather than by the
short name PHP binds, and split entries at commas. It wrote a file that did not compile,
put entries into an unrelated constant, put mutations into the Query array, and
miscounted brackets around PHP 8 attributes. Its internals were rewritten. What follows
describes the rewrite; where it differs from the original design the difference is
marked. Two rules came out of it that override everything else in this section:

1. **Never write a file that does not compile, and never change what a hand-written
   reference means.** A class is imported only when its short name is free in the file:
   bound by no import (grouped and aliased imports included), declared by no class in the
   file, and used unqualified nowhere in it, including as the first part of a qualified
   name such as `Type\Action\ActionType`. Otherwise the generated entry names the class
   in full (`\GraphQL\Type\Definition\Type::nonNull(...)`), which always works. If the
   imports cannot be read with confidence (a `use` statement not fully understood, a
   braced namespace, several namespaces in one file) nothing is imported and everything
   is fully qualified.
2. **When unsure, change nothing.** See 7.3.

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
- **Imports** *(revised)*. A missing `use` line is added only under rule 1 above, and
  only for an entry that was actually written. It goes after any `declare` and
  `namespace`, in alphabetical position when every existing import is a simple
  one-line `use`, otherwise after the last one. Imports are never removed.
- **Removed models.** Owned entries for a model that no longer exists are reported,
  not deleted, consistent with orphaned files. *(revised)* A model merely left out of
  this run by `--only` still exists: the editor is given every known entity, and
  reports none of their entries.
- **Indentation** of inserted entries copies that of the neighbouring entry, and
  *(revised)* line endings follow whichever the file mostly uses.
- **Owned means directly led by the marker** *(revised)*. If anything but whitespace
  sits between the marker and the entry's code, a note the developer added for
  instance, the entry is treated as hand-written. A trailing comment on the same line
  as an entry's comma belongs to that entry and stays with it, including on an owned
  entry that is rewritten.
- **Field names** *(revised)*. An entry's name is its first string literal, as in
  `createField('name', ...)` and `'name' => [...]`, or the value of the `'name'` key when
  the entry is itself an array. Two entities that would define the same field name,
  and the same name marked as generated twice, are reported.
- **Idempotent.** A second run with no model changes leaves the file byte-identical.

### 7.2 Parsing

*(revised)* PHP's built-in `token_get_all`, wrapped by `Tokens`, which gives every token
its byte offset, its bracket depth, its enclosing bracket and the matching close, and
gives the same answers on PHP 7.4 and 8.x. The two tokenizers differ where it matters:
on 8.x an attribute `#[` opens a bracket and a qualified name is one token; on 7.4 an
attribute is a comment and a name is several tokens, possibly with whitespace between
them. The schema tests run on both.

`FieldsArrayLocator` is deliberately narrow:

1. A fields array is reached only as `'query' => new ObjectType([ ... ])` (or
   `'mutation' => ...`), with `'fields' => [` a **direct** key of that config array. A
   stray `'query' =>` elsewhere in the file, or a nested ObjectType's `'fields'`, is never
   mistaken for it. More than one match is ambiguous.
2. The array must be written one entry per line, with its closing bracket on a line of
   its own. An entry then owns whole lines: its leading comments, its code, its comma,
   and whatever else is on the comma's line.
3. A last entry with no comma gets one, placed before any trailing comment, when it
   stops being last.

Every byte the generator does not own is copied through untouched. `nikic/php-parser`
with its format-preserving printer would also work, but is a heavy dependency for
inserting lines into two arrays.

### 7.3 Failing safe

If either `fields` array cannot be found in the expected shape (fields built by a
method call, a closure, `array()`, merged arrays, entries sharing a line, an ambiguous
file), the generator changes nothing in that file, says what it was looking for, and
prints the entries to paste, fully qualified and grouped by the array they belong in.
Types and tests are still written.

*(revised)* A schema with no `'mutation'` type is fine when no mutation entry is wanted,
that is, when every entity of the run is read-only. As a last guard the result is parsed
before it is returned; if it would not parse, nothing is written, and the message says
whether the file itself does not parse under the running PHP or the editor is at fault.

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
    protected function inputClass(): ?string { return ClientInput::class; }   // null when read-only (revised)
    protected function entityName(): string { return 'client'; }
    protected function keyField(): string { return 'id'; }
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
2. *(revised after Gate D)* **A table that cannot roll back is not written to.**
   Rollback only cleans up where the table's storage engine has transactions. On a
   MyISAM table it does nothing, and a test's rows would stay in a real database for
   good; FrontAccounting's schema has historically used MyISAM. So before it writes,
   the lifecycle test asks MySQL or MariaDB whether the Type's table has transactions
   (`information_schema.ENGINES.TRANSACTIONS`), and if not it skips itself and says why.
   A project may override `allowNonTransactionalTables()` to accept the consequences.
   The check covers the Type's own table, on MySQL and MariaDB. `ModelType::tableName()`
   exists so that the test case can ask.
   The check is made in `execute()`, before any mutation, together with beginning the
   clean-up transaction, so that a test a project adds to its own once-only test file is
   as safe as the inherited ones. A mutation is recognised by parsing the document; a
   regex on its text was bypassed by a leading comment, a byte order mark, or a fragment.
   A write that does not go through `execute()` or `upsert()` (a model written directly,
   a statement run on the PDO) is the project's to look after.
3. **Cleanup is by rollback, not truncation.** `setUp` begins a transaction on the
   container's PDO and `tearDown` rolls it back. `GraphQLCudTestCase` truncates
   tables, which is dangerous if a test configuration ever points at a real
   FrontAccounting company database. This is why `ModelType` uses savepoints (4.3).
4. *(revised after Gate C)* **An update that is not tested says so.** When
   `sampleUpdate()` is empty, the inherited lifecycle test asserts create, view and
   delete and then marks itself incomplete, instead of passing in silence. The generator
   picks a non-Boolean field to update where there is one, a Boolean otherwise, and
   for an entity of nothing but keys it writes a comment saying none could be chosen.
5. **Sample data is a starting point.** Values derive from the GraphQL type
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

Commands match Anorm's *(revised: the full list, as `docker/anorm-graphql help` prints
it)*: stack `init`, `up [--build] [--tools]`, `down [-v]`, `build`, `rebuild`, `ps`,
`logs`, `info`; generating `make`; testing and quality `test [args]`, `test:full`,
`coverage`, `ci`, `quality`, `analyze`, `cs [check|fix]`; inside the container
`composer`, `php`, `exec`, `shell`, `root-shell`; database `mysql`, `db-reset`; `help`.

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
