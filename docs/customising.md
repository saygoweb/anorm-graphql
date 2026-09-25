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
