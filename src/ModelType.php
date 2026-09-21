<?php

namespace Anorm\GraphQL;

use Anorm\DataMapper;
use Anorm\MangoQuery;
use Anorm\MangoQueryParser;
use Anorm\Model;
use Anorm\QueryBuilder;
use Anorm\SqlCondition;
use DI\Container;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;

/**
 * The resolver logic every model-backed Type shares: list, upsert and delete.
 *
 * A generated base class supplies modelClass() and fields(). A project's own
 * subclass overrides authorize() and beforeWrite(), or a whole resolver.
 */
abstract class ModelType extends ObjectType
{
    public const VERB_LIST = 'list';
    public const VERB_CREATE = 'create';
    public const VERB_EDIT = 'edit';
    public const VERB_DELETE = 'delete';

    /**
     * Words Anorm's Mango parser reads as operators even without a `$`. A field with
     * one of these names cannot appear in a selector: the parser would not treat it
     * as a field. Kept in step with Anorm\MangoQueryParser::isOperator().
     */
    private const OPERATOR_WORDS = [
        'and', 'or', 'not', 'nor', 'eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'nin',
        'exists', 'type', 'regex', 'beginswith', 'all', 'elemmatch', 'allmatch', 'size',
    ];

    /** @var int Makes each savepoint name unique within the process */
    private static $savepoints = 0;

    /** @return string Fully qualified class name of the Anorm model */
    abstract protected function modelClass(): string;

    /** @return array<int, array<string, mixed>> Built field definitions */
    abstract protected function fields(): array;

    protected function newModel(Container $context): Model
    {
        $class = $this->modelClass();
        return new $class($context->get(\PDO::class));
    }

    /**
     * Fixed property => value pairs that every row of this Type has.
     *
     * For a table that holds several kinds of row, told apart by a discriminator
     * column: sales orders and quotations, invoices and payments. The scope is ANDed
     * into every list and every read by key, stamped on every write, and an input
     * that tries to set a scope property to anything else is refused. Within the
     * scope the model's key must be unique.
     *
     * @return array<string, mixed>
     */
    protected function scope(): array
    {
        return [];
    }

    /**
     * Throw to refuse. $model is null for list and create.
     */
    protected function authorize(string $verb, ?Model $model, Container $context): void
    {
    }

    /**
     * Called after the input has been applied to the model and before it is written.
     */
    protected function beforeWrite(Model $model, array $input, bool $isUpdate, Container $context): void
    {
    }

    public function resolveList($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_LIST, null, $context);

        $probe = $this->newModel($context);
        $builder = $this->scopedQuery($probe, isset($args['query']) ? $args['query'] : null, []);
        $rows = [];
        foreach ($builder->some() as $model) {
            $rows[] = Mapper::toArray($model);
        }
        return $rows;
    }

    public function resolveUpsert($root, $args, Container $context): array
    {
        $probe = $this->newModel($context);
        $this->assertStaticMode($probe);
        $key = $probe->mapper()->modelPrimaryKey;
        $scope = $this->scope();
        // Never taken from the input: the key of an existing row comes from the row,
        // and scope properties are fixed.
        $notFromInput = array_merge([$key], array_keys($scope));

        return $this->transactional($probe->getPdo(), function () use ($args, $context, $key, $scope, $notFromInput) {
            $rows = [];
            foreach ($args['input'] as $input) {
                $this->assertInputWithinScope($input, $scope);
                $isUpdate = isset($input[$key]) && $input[$key] !== '';
                if ($isUpdate) {
                    $model = $this->findOrFail($context, $input[$key]);
                    $this->authorize(self::VERB_EDIT, $model, $context);
                } else {
                    $model = $this->newModel($context);
                    $this->authorize(self::VERB_CREATE, null, $context);
                }
                Mapper::toModel($model, $input, $notFromInput);
                foreach ($scope as $property => $value) {
                    $model->$property = $value;
                }
                $this->beforeWrite($model, $input, $isUpdate, $context);
                $model->write();
                $rows[] = Mapper::toArray($model);
            }
            return $rows;
        });
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        $probe = $this->newModel($context);
        $this->assertStaticMode($probe);

        return $this->transactional($probe->getPdo(), function () use ($args, $context) {
            $rows = [];
            foreach ($args['id'] as $id) {
                $model = $this->findOrFail($context, $id);
                $this->authorize(self::VERB_DELETE, $model, $context);
                $rows[] = Mapper::toArray($model);
                $model->delete();
            }
            return $rows;
        });
    }

    /**
     * The one place a query is built. Everything a client supplies is either checked
     * against the model's property map (names) or bound (values); nothing is
     * concatenated. Model::read() is deliberately not used: up to Anorm 3.2.0 it
     * concatenates the id into its SQL.
     *
     * @param array<string, mixed>|null $query A MangoInput value, or null
     * @param array<string, mixed> $equals Further property => value conditions, bound
     */
    private function scopedQuery(Model $probe, ?array $query, array $equals): QueryBuilder
    {
        $mapper = $probe->mapper();
        $builder = DataMapper::find($this->modelClass(), $probe->getPdo());
        $mango = $this->mangoQuery($query, $mapper->map);

        $condition = SqlCondition::empty();
        if ($mango !== null && $mango->hasConditions()) {
            try {
                $condition = (new MangoQueryParser($mapper))->parseSelector($mango->selector);
            } catch (\InvalidArgumentException $e) {
                throw new UserError("Argument 'query.selector' is not valid: " . $e->getMessage());
            } catch (\TypeError $e) {
                throw new UserError("Argument 'query.selector' is not valid");
            }
        }
        $n = 0;
        foreach (array_merge($this->scope(), $equals) as $property => $value) {
            if (!isset($mapper->map[$property])) {
                throw new \LogicException(get_class($this) . ": '$property' is not a property of " . $this->modelClass());
            }
            $name = ':agq_' . (++$n);
            $fixed = new SqlCondition('`' . $mapper->map[$property] . '` = ' . $name, [$name => $value]);
            $condition = $condition->isEmpty() ? $fixed : $condition->combine($fixed, 'AND');
        }
        if (!$condition->isEmpty()) {
            $builder->where($condition);
        }

        if ($mango !== null) {
            if ($mango->hasSort()) {
                try {
                    $builder->orderBy((new MangoQueryParser($mapper))->parseSort($mango->sort));
                } catch (\InvalidArgumentException | \TypeError $e) {
                    throw new UserError("Argument 'query.sort' is not valid");
                }
            }
            if ($mango->limit !== null) {
                $builder->limit($mango->limit, $mango->skip === null ? 0 : $mango->skip);
            } elseif ($mango->skip !== null && $mango->skip > 0) {
                $builder->limit(PHP_INT_MAX, $mango->skip);
            }
        }
        return $builder;
    }

    /**
     * The row with this key, within the scope.
     *
     * @param mixed $id Straight from the client, so not yet known to be a string or an integer;
     *                  it is bound, never concatenated
     */
    private function findOrFail(Container $context, $id): Model
    {
        if (!is_int($id) && !is_string($id)) {
            throw new UserError($this->name . ' id must be a string or an integer');
        }
        $probe = $this->newModel($context);
        $key = $probe->mapper()->modelPrimaryKey;
        $model = $this->scopedQuery($probe, null, [$key => $id])->one();
        // MySQL compares a string with a numeric column by casting the string, so a
        // bound "5 anything" finds row 5. That is not the row the client named.
        if ($model instanceof Model && is_numeric($model->$key) && (string) $model->$key !== (string) $id) {
            $model = false;
        }
        if (!$model instanceof Model) {
            throw new UserError($this->name . " id '" . substr((string) $id, 0, 40) . "' not found");
        }
        return $model;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $scope
     */
    private function assertInputWithinScope(array $input, array $scope): void
    {
        foreach ($scope as $property => $value) {
            if (array_key_exists($property, $input) && $input[$property] != $value) {
                throw new UserError("'$property' is fixed for " . $this->name . ' and cannot be set');
            }
        }
    }

    /**
     * All-or-nothing needs every statement of a mutation to be transactional. Anorm's
     * dynamic mode runs DDL during a write, and DDL commits implicitly in MySQL: it
     * would silently commit the rows before it, and the caller's own transaction too.
     */
    private function assertStaticMode(Model $probe): void
    {
        if ($probe->mapper()->mode === DataMapper::MODE_DYNAMIC) {
            throw new \LogicException(
                $this->modelClass() . " is in Anorm's dynamic mode, which runs DDL during a write; "
                . 'DDL commits implicitly, so mutations through ModelType need static mode'
            );
        }
    }

    /**
     * @param array<string, mixed>|null $query A MangoInput value
     * @param array<string, string> $map The model's property => column map
     */
    private function mangoQuery(?array $query, array $map): ?MangoQuery
    {
        if (!$query) {
            return null;
        }
        $mango = [];
        if (isset($query['selector']) && $query['selector'] !== '') {
            // Decoded twice on purpose. As objects, a JSON object and a JSON list stay
            // distinguishable, which the field-name check depends on; as arrays is what
            // Anorm takes.
            $asObjects = json_decode($query['selector']);
            if (!$asObjects instanceof \stdClass) {
                throw new UserError("Argument 'query.selector' is not a valid JSON object");
            }
            $this->assertKnownFields($asObjects, $map);
            $mango[MangoQuery::MANGO_SELECTOR] = json_decode($query['selector'], true);
        }
        if (isset($query[MangoQuery::MANGO_SORT])) {
            foreach ($query[MangoQuery::MANGO_SORT] as $item) {
                foreach (is_array($item) ? array_keys($item) : [$item] as $name) {
                    $this->assertFieldName((string) $name, $map);
                }
            }
        }
        foreach ([MangoQuery::MANGO_LIMIT, MangoQuery::MANGO_SKIP, MangoQuery::MANGO_SORT] as $name) {
            if (isset($query[$name])) {
                $mango[$name] = $query[$name];
            }
        }
        try {
            return MangoQuery::fromArray($mango);
        } catch (\InvalidArgumentException $e) {
            throw new UserError("Argument 'query' is not valid: " . $e->getMessage());
        }
    }

    /**
     * Refuse any field name in a selector that is not a property of the model.
     *
     * This is a security boundary, not a nicety. Anorm's Mango parser puts a name it
     * does not recognise into the SQL between backticks, unescaped, and these names
     * arrive from the API's clients.
     *
     * @param mixed $node A selector decoded as objects, or any part of one. Every
     *                    property of an object is a name the client chose, even one
     *                    that looks like a number; the items of a list are not names.
     * @param array<string, string> $map The model's property => column map
     */
    private function assertKnownFields($node, array $map): void
    {
        if (is_array($node)) {
            foreach ($node as $item) {
                $this->assertKnownFields($item, $map);
            }
            return;
        }
        if (!$node instanceof \stdClass) {
            return;
        }
        foreach (get_object_vars($node) as $name => $value) {
            $name = (string) $name;
            if ($name === '' || $name[0] !== '$') {
                $this->assertFieldName($name, $map);
            }
            $this->assertKnownFields($value, $map);
        }
    }

    /**
     * @param array<string, string> $map The model's property => column map
     */
    private function assertFieldName(string $name, array $map): void
    {
        if (!isset($map[$name])) {
            throw new UserError("Argument 'query' names an unknown field '" . substr($name, 0, 40) . "'");
        }
        if (in_array(strtolower($name), self::OPERATOR_WORDS, true)) {
            throw new UserError(
                "Argument 'query' cannot filter or sort on '$name': Anorm's Mango parser reads that word as an operator"
            );
        }
    }

    /**
     * Run $work so that it happens entirely or not at all. Inside somebody else's
     * transaction that means a savepoint, because MySQL transactions do not nest.
     *
     * @return mixed Whatever $work returns
     */
    private function transactional(\PDO $pdo, callable $work)
    {
        $savepoint = null;
        if ($pdo->inTransaction()) {
            $savepoint = 'anorm_graphql_' . (++self::$savepoints);
            $pdo->exec('SAVEPOINT ' . $savepoint);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $work();
        } catch (\Throwable $e) {
            try {
                if ($savepoint !== null) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                } else {
                    $pdo->rollBack();
                }
            } catch (\Throwable $rollbackFailure) {
                // The rollback can fail too, for one if something in $work committed
                // implicitly. The error worth reporting is still the one that got us here.
            }
            throw $e;
        }
        try {
            if ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            // Nothing in $work failed, yet the transaction it ran in is gone. In MySQL
            // that means a statement committed implicitly, which DDL does. It cannot be
            // undone from here, so say exactly what happened rather than let a bare
            // "SAVEPOINT does not exist" reach whoever has to work it out.
            throw new \RuntimeException(
                get_class($this) . ': a statement inside this mutation committed implicitly (DDL does, in MySQL). '
                . 'Its rows, and any transaction the caller had open, are already committed, so all-or-nothing '
                . 'could not be honoured. Do not run DDL from authorize(), beforeWrite() or a model.',
                0,
                $e
            );
        }
        return $result;
    }
}
