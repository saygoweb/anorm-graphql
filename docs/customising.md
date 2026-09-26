# Customising generated Types

`anorm-graphql` writes `<Entity>Type.php` and `<Entity>Input.php` once, using the
generation-gap pattern: the regenerated `<Entity>TypeBase` / `<Entity>InputBase`
classes hold the columns, and your once-only subclass holds everything specific to
the project. Regeneration never touches the subclass.

Every override point below is inherited from `Anorm\GraphQL\ModelType`
(`src/ModelType.php`). Signatures must match it exactly, or PHP rejects the
override. Each example here was compiled by generating a real `WidgetType.php` (or
`DocumentType.php`) with `docker/anorm-graphql make` and running
`docker/anorm-graphql php -l` against the result with the code pasted in; none of
them is fabricated.

## A project base class via `--type-base`

When every Type in a project needs the same override — the same rights check, the
same `newModel()` — put it in one abstract class and have every generated base
extend it:

```
vendor/bin/anorm-graphql.php make ... --type-base 'App\GraphQL\Type\AppModelType'
```

```php
<?php

namespace App\GraphQL\Type;

use Anorm\GraphQL\ModelType;
use Anorm\Model;
use App\Rights;
use DI\Container;

abstract class AppModelType extends ModelType
{
    /** @return array<string, string> verb => right; a verb with no entry is refused */
    abstract protected function rights(): array;

    protected function authorize(string $verb, ?Model $model, Container $context): void
    {
        $rights = $this->rights();
        if (!isset($rights[$verb]) || !$context->get(Rights::class)->can($rights[$verb])) {
            throw new \GraphQL\Error\UserError("Not allowed to $verb");
        }
    }
}
```

The generated `WidgetTypeBase` then reads `abstract class WidgetTypeBase extends
\App\GraphQL\Type\AppModelType`, written fully qualified so it cannot collide with
anything the file imports. Because `rights()` is abstract, a `WidgetType` that does
not declare it cannot be instantiated: a Type nobody configured fails closed rather
than open.

The class must extend `ModelType`, must not be final, and must be loadable when the
generator runs. Leaving the option out gives exactly the output earlier versions
gave.

## A rights check in `authorize()`

Declared in `ModelType` as a method that does nothing. It is not abstract: override it as shown below.

```php
protected function authorize(string $verb, ?Model $model, Container $context): void
```

Verbs are the `ModelType::VERB_*` constants: `VERB_LIST`, `VERB_CREATE`,
`VERB_EDIT`, `VERB_DELETE`. `$model` is null for `list` and `create`. Throw to
refuse; `resolveList`, `resolveUpsert` and `resolveDelete` all call `authorize`
before doing anything else.

```php
<?php

namespace Api\GraphQL\Type\Widget;

use Anorm\GraphQL\ModelType;
use Anorm\Model;
use Api\GraphQL\Type\Widget\Base\WidgetTypeBase;
use App\Rights;
use DI\Container;
use GraphQL\Error\UserError;

class WidgetType extends WidgetTypeBase
{
    protected function authorize(string $verb, ?Model $model, Container $context): void
    {
        $rights = $context->get(Rights::class);
        if (!$rights->can('widget.' . $verb)) {
            throw new UserError("Not allowed to $verb widgets");
        }
        if ($verb === ModelType::VERB_DELETE && $model !== null && $model->ownerId === null) {
            throw new UserError('Cannot delete an unowned widget');
        }
    }
}
```

## Stamping columns in `beforeWrite()`

Declared in `ModelType` as a method that does nothing. It is not abstract: override it as shown below.

```php
protected function beforeWrite(Model $model, array $input, bool $isUpdate, Container $context): void
```

Called after the input has been applied to the model (`Mapper::toModel`) and before
`write()`. This is where to stamp audit columns such as `dtu` (date time updated)
and `uu` (updated by) that should never come from client input.

```php
<?php

namespace Api\GraphQL\Type\Widget;

use Anorm\Model;
use Api\GraphQL\Type\Widget\Base\WidgetTypeBase;
use App\CurrentUser;
use DI\Container;

class WidgetType extends WidgetTypeBase
{
    protected function beforeWrite(Model $model, array $input, bool $isUpdate, Container $context): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$isUpdate) {
            $model->dtu = $now;
        }
        $model->uu = $context->get(CurrentUser::class)->id();
    }
}
```

## A computed field via `fields()`

Abstract in `ModelType`, and implemented by the generated base class. In your own Type, override it and
call the parent, as shown below.

```php
protected function fields(): array
```

`fields()` returns the array `ObjectBuilder::setFields()` is built from. Add to it
with `array_merge(parent::fields(), [...])` so the generated columns are kept.
A computed field needs its own resolver, since there is no column behind it —
`FieldBuilder::setResolver()` takes the row (already `Mapper::toArray`'d) as its
first argument.

```php
<?php

namespace Api\GraphQL\Type\Widget;

use Anorm\GraphQL\Builder\FieldBuilder;
use Api\GraphQL\Type\Widget\Base\WidgetTypeBase;
use GraphQL\Type\Definition\Type;

class WidgetType extends WidgetTypeBase
{
    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('totalValue', Type::float())
                ->setResolver(function (array $row) {
                    return $row['quantity'] === null || $row['price'] === null
                        ? null
                        : $row['quantity'] * $row['price'];
                })
                ->build(),
        ]);
    }
}
```

## Constructor dependencies

`WidgetTypeBase::__construct()` takes no arguments. To inject a project service
(a `CurrentUser`, say), override the constructor in the once-only subclass and call
`parent::__construct()` with no arguments; PHP-DI autowires the extra typed
parameter when the container resolves `WidgetType::class`.

```php
<?php

namespace Api\GraphQL\Type\Widget;

use Api\GraphQL\Type\Widget\Base\WidgetTypeBase;
use App\CurrentUser;

class WidgetType extends WidgetTypeBase
{
    /** @var CurrentUser */
    private $currentUser;

    public function __construct(CurrentUser $currentUser)
    {
        parent::__construct();
        $this->currentUser = $currentUser;
    }
}
```

## `newModel()` for a project-specific PDO

```php
protected function newModel(Container $context): Model
{
    $class = $this->modelClass();
    return new $class($context->get(\PDO::class));
}
```

That is `ModelType`'s default. `newModel()` is only ever used to construct the
*probe* model that `resolveList`, `resolveUpsert` and `resolveDelete` use to find
the key, the mapper and the PDO to query with — **it is not where list rows come
from**. List rows are built by Anorm's `QueryBuilder` from `DataMapper::find()`,
using the same PDO. So a project whose models take their PDO from `Anorm::pdo()`
rather than the container overrides `newModel()` alone; nothing else needs to
change.

```php
<?php

namespace Api\GraphQL\Type\Widget;

use Anorm\Anorm;
use Anorm\Model;
use Api\GraphQL\Type\Widget\Base\WidgetTypeBase;
use DI\Container;

class WidgetType extends WidgetTypeBase
{
    protected function newModel(Container $context): Model
    {
        $class = $this->modelClass();
        return new $class(Anorm::pdo());
    }
}
```

## `scope()` for a discriminated table

```php
protected function scope(): array
{
    return [];    // ModelType's default
}
```

`scope()` returns fixed `property => value` pairs that every row of the Type has.
It exists for a table that holds several kinds of row told apart by a discriminator
column — FrontAccounting's `sales_orders.trans_type` or `debtor_trans.type` are the
motivating case. The scope is ANDed into every list and every read by key, stamped
onto every write, and an input that tries to set a scope property to a different
value is refused as a client-safe error. Within the scope the model's key must
still be unique.

A discriminator column is usually applied as a bound `WHERE`, **not** through the
Mango selector: it is very often named `type`, and `type` is one of the words
Anorm's Mango parser reads as an operator, so it could never be filtered through
`query.selector` in the first place (see the README's "Filtering with Mango"
section).

Compiled against this repository's `DocumentModel` fixture (`id`, `type`, `title` —
a table shaped like `debtor_trans`, used elsewhere in the test suite for exactly
this scenario):

```php
<?php

namespace Api\GraphQL\Type\Document;

use Api\GraphQL\Type\Document\Base\DocumentTypeBase;

/**
 * documents holds several kinds of row, told apart by `type` (as FrontAccounting's
 * debtor_trans does for sales orders, quotations, invoices...). `type` is also a word
 * Anorm's Mango parser reads as an operator, so it cannot go through the selector:
 * scope() applies it as a bound WHERE condition instead.
 */
class DocumentType extends DocumentTypeBase
{
    private const TYPE_SALES_ORDER = 30;

    protected function scope(): array
    {
        return ['type' => self::TYPE_SALES_ORDER];
    }
}
```

A `SalesOrderType` living over the same `documents` table as a `QuotationType`
would be two once-only subclasses like this, each generated read-only or read-write
as appropriate, each with its own `scope()` value.

## Separate create and update: `--mutations create-update`

By default (`upsert`) one Input and one resolver (`resolveUpsert`) serve both create
and update: an input without the key creates, an input with it updates. With
`--mutations create-update` a writable entity instead gets two Inputs and two
resolvers:

```php
public function resolveCreate($root, $args, Container $context): array;
public function resolveUpdate($root, $args, Container $context): array;
```

Both are inherited from `ModelType` and go through the same protected `write()` as
`resolveUpsert`: all-or-nothing per batch, the same `authorize()` verbs
(`VERB_CREATE` for a create, `VERB_EDIT` for an update) and the same `beforeWrite()`
signature (`$isUpdate` false for a create, true for an update). A create whose input
carries a non-empty key, or an update whose input carries none, is a client-safe
`UserError` before anything is written.

The generated `resolveCreate()` assumes the key is generated (an `AUTO_INCREMENT`
column, typically): it refuses any input that carries one at all. For an entity whose
key a client chooses — FrontAccounting's `stock_id`, say — `create-update` as
generated cannot be used unmodified. Override `resolveCreate()` in the once-only
Type and call the inherited `write()` directly (it is `protected` for exactly this),
passing whichever `$mode` fits the entity's own rules instead of refusing a supplied
key outright.

`<Entity>CreateInput` has no key field — a create makes it — and a property whose
model docblock says `@required` is non-null there. `<Entity>UpdateInput` has the key
as `ID!`; every other field is optional, and one left out of the input is left as it
was (`Mapper::toModel()` only sets keys present in the array).

Each Input is its own once-only subclass over a regenerated Base, exactly like the
upsert Input, and each has its own `fields()` hook. Compiled by generating a real
`WidgetCreateInput.php` with `docker/anorm-graphql make ... --mutations create-update`
and adding a create-only field:

```php
<?php

namespace Api\GraphQL\Type\Widget;

use Anorm\GraphQL\Builder\FieldBuilder;
use Api\GraphQL\Type\Widget\Base\WidgetCreateInputBase;
use GraphQL\Type\Definition\Type;

class WidgetCreateInput extends WidgetCreateInputBase
{
    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('initialStock', Type::int())->build(),
        ]);
    }
}
```

`WidgetUpdateInput` follows the same shape, extending `WidgetUpdateInputBase`.

Switching an existing project from `upsert` to `create-update` (or back) leaves the
old Input files and `ApiSchema.php` entries in place, reported as orphaned rather
than deleted, so nothing is lost by trying it.

## Inputs of rows written inside another: `--input-only`

For an entity whose rows are only ever written as part of another entity's input —
FrontAccounting's `SalesOrderLine`, written inside a `SalesOrderInput`'s lines rather
than through its own mutation — `--input-only <names>` writes just the Input(s)
(`<Entity>Input` under `upsert`, `<Entity>CreateInput` / `<Entity>UpdateInput` under
`create-update`), with no Type, no test, and no `ApiSchema` entries at all: no
`<entity>List`, no `<entity>Upsert` / `<entity>Create` / `<entity>Update` /
`<entity>Delete`.

Combine it with `--readonly` on the same name to also get the read-only Type and its
`<entity>List` (for reading the rows back, nested under their parent), while still
emitting no mutation of the entity's own — its rows are still only ever *written*
through the parent's input:

```
vendor/bin/anorm-graphql.php make ... --readonly SalesOrderLine --input-only SalesOrderLine
```

## No update, or no delete: `--without-update` and `--without-delete`

Some documents are only ever created and voided, never edited in place — a posted
invoice, say, where a correction means voiding it and entering it again. For those,
`--without-update <names>` and `--without-delete <names>` drop the mutation an entity
would otherwise get, with everything else about it generated exactly as before.

`--without-update <names>` needs `--mutations create-update`: under `upsert` there is
no separate Update to drop (one Input and one resolver serve both create and
update), so naming an entity is exit 2:

```
Error: --without-update names 'Invoice', which needs --mutations create-update, not 'upsert'
```

Named under `create-update`, the entity gets `<entity>Create` and no
`<entity>Update` at all: no `<Entity>UpdateInputBase.php` / `<Entity>UpdateInput.php`,
and no `resolveUpdate` entry in `ApiSchema.php`. Its generated test drops the update
step of the lifecycle test cleanly — no `markTestIncomplete` for "update not
exercised", since there is no update to exercise — while everything else (the create
step, the list, and delete unless that is dropped too) still runs.

`--without-delete <names>` works under either `--mutations` mode: the entity gets no
`<entity>Delete` at all, and its generated test's lifecycle skips the delete step the
same way.

```
vendor/bin/anorm-graphql.php make ... --mutations create-update --without-update Invoice --without-delete Invoice
```

Switching an entity to `--without-update` leaves its old `<Entity>UpdateInput.php` /
`Base/<Entity>UpdateInputBase.php` in place, reported as orphaned rather than
deleted, exactly like switching `--mutations` (above); the `<entity>Update` schema
entry it leaves behind is reported the same way `ApiSchema.php` always reports an
entry no model produces any more.

## Dates

A model property declared `\DateTimeInterface`, `\DateTimeImmutable`, or an `@var`
docblock naming any class implementing `\DateTimeInterface` (including `\DateTime`,
as a docblock only — see below), is generated as a `Date` field rather than a
`String`: ISO 8601 `YYYY-MM-DD`, both out (`serialize`) and in (`parseValue` /
`parseLiteral`). An invalid value is a client-safe `GraphQL\Error\Error`
(`Date must be a date as YYYY-MM-DD: ...`), never an internal server error. MySQL's
zero date (`0000-00-00`) serializes as `null` — whether it arrives as the raw string
or, once a model applies a date transformer, as the `\DateTime` it rolls over to
(`-0001-11-30`).

The generator never reads the column, only the declared PHP type: declare a
datetime property (one with a time component) as `string` to keep it a `String`
— `Date` only ever prints and parses the day.

**A natively typed `\DateTime` property is left as `String`, on purpose.**
`parseValue()` / `parseLiteral()` always hand back a `\DateTimeImmutable`, and PHP
enforces a native property type at assignment, so `public \DateTime $dueOn;` would
throw a `TypeError` on every create or update. Declare the property
`\DateTimeInterface` or `\DateTimeImmutable` instead (typed or `@var`), or leave it
untyped with an `@var \DateTime` docblock — untyped, PHP never enforces it, so
assigning a `\DateTimeImmutable` there is safe:

```php
public function __construct(\PDO $pdo)
{
    parent::__construct($pdo, DataMapper::create($pdo, 'events', DataMapper::autoMap($this)));
    // Anorm hands a plain string back unless told otherwise: this makes it a real
    // \DateTime, and writes it back as Y-m-d.
    $this->mapper()->transformers['due_on'] = new \Anorm\Transform\SqlDateTimeTransform('Y-m-d');
}

/** @var \DateTime */
public $dueOn;
```

The scalar itself is one shared instance, `Anorm\GraphQL\Type\DateType::instance()`:
a schema may hold only one type named `Date`, and generated code refers to this one,
fully qualified, so it never needs an import and is never registered in the
container. A computed date field added in your own `fields()` override must use the
same instance — `FieldBuilder::create('someDate', \Anorm\GraphQL\Type\DateType::instance())`.
`DateType`'s constructor is private, so neither `new DateType(...)` nor a container
lookup (`$container->get(DateType::class)`) can build a second one: both are refused
before the schema gets the chance to hold two types named `Date` and fail to build.

## Replacing `resolveUpsert` wholesale

```php
public function resolveUpsert($root, $args, Container $context): array;
```

Some tables must never be written directly — FrontAccounting's transactional
tables bypass GL postings if a row is upserted straight into them. The safe
starting point is to generate the entity `--readonly` (no `Input`, no
`<entity>Upsert` / `<entity>Delete` in `ApiSchema.php`), then override the whole
resolver in the once-only Type to go through the application's own write path.
Since the entity is `--readonly`, `<entity>Upsert` gets no generated schema entry
either: add it to `ApiSchema.php` by hand, without the `// anorm-graphql` marker,
so it is never touched by later runs.

Compiled against a `Widget` entity generated with `--readonly Widget` (so
`WidgetTypeBase` has no `Input` counterpart and the once-only `WidgetType.php`
starts the same way as a read-write entity's, minus the `Input` mention):

```php
<?php

namespace Api\GraphQL\Type\Widget;

use Anorm\GraphQL\Mapper;
use Api\GraphQL\Type\Widget\Base\WidgetTypeBase;
use App\Accounting\WidgetPosting;
use DI\Container;

/**
 * Generated --readonly, because writing this table directly bypasses application
 * postings. resolveUpsert is replaced wholesale, going through WidgetPosting instead
 * of ModelType's write(); the widgetUpsert field itself is then added by hand to
 * ApiSchema.php, since a --readonly entity gets no generated mutation entry.
 */
class WidgetType extends WidgetTypeBase
{
    public function resolveUpsert($root, $args, Container $context): array
    {
        $posting = $context->get(WidgetPosting::class);
        $rows = [];
        foreach ($args['input'] as $input) {
            $rows[] = Mapper::toArray($posting->upsert($input));
        }
        return $rows;
    }
}
```

Under `--mutations create-update`, replace `resolveCreate` and `resolveUpdate` the
same way instead.
