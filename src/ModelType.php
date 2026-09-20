<?php

namespace Anorm\GraphQL;

use Anorm\DataMapper;
use Anorm\MangoQuery;
use Anorm\Model;
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
        $builder = DataMapper::find($this->modelClass(), $probe->getPdo());
        $mango = $this->mangoQuery(isset($args['query']) ? $args['query'] : null, $probe->mapper()->map);
        if ($mango !== null) {
            $builder->byMango($mango);
        }
        $rows = [];
        foreach ($builder->some() as $model) {
            $rows[] = Mapper::toArray($model);
        }
        return $rows;
    }

    public function resolveUpsert($root, $args, Container $context): array
    {
        $probe = $this->newModel($context);
        $key = $probe->mapper()->modelPrimaryKey;

        return $this->transactional($probe->getPdo(), function () use ($args, $context, $key) {
            $rows = [];
            foreach ($args['input'] as $input) {
                $model = $this->newModel($context);
                $isUpdate = isset($input[$key]) && $input[$key] !== '';
                if ($isUpdate) {
                    $this->readOrFail($model, $input[$key]);
                    $this->authorize(self::VERB_EDIT, $model, $context);
                } else {
                    $this->authorize(self::VERB_CREATE, null, $context);
                }
                Mapper::toModel($model, $input, $isUpdate ? [] : [$key]);
                $this->beforeWrite($model, $input, $isUpdate, $context);
                $model->write();
                $rows[] = Mapper::toArray($model);
            }
            return $rows;
        });
    }

    public function resolveDelete($root, $args, Container $context): array
    {
        $pdo = $this->newModel($context)->getPdo();

        return $this->transactional($pdo, function () use ($args, $context) {
            $rows = [];
            foreach ($args['id'] as $id) {
                $model = $this->newModel($context);
                $this->readOrFail($model, $id);
                $this->authorize(self::VERB_DELETE, $model, $context);
                $rows[] = Mapper::toArray($model);
                $model->delete();
            }
            return $rows;
        });
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
            $selector = json_decode($query['selector'], true);
            if (!is_array($selector)) {
                throw new UserError("Argument 'query.selector' is not a valid JSON object");
            }
            $this->assertKnownFields($selector, $map);
            $mango[MangoQuery::MANGO_SELECTOR] = $selector;
        }
        if (isset($query[MangoQuery::MANGO_SORT])) {
            foreach ($query[MangoQuery::MANGO_SORT] as $item) {
                $this->assertKnownFields(is_array($item) ? $item : [(string) $item => 'asc'], $map);
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
     * Refuse any field name that is not a property of the model.
     *
     * This is a security boundary, not a nicety. Anorm's Mango parser puts a name it
     * does not recognise into the SQL between backticks, unescaped, and these names
     * arrive from the API's clients.
     *
     * @param array<int|string, mixed> $selector A selector, or any part of one
     * @param array<string, string> $map The model's property => column map
     */
    private function assertKnownFields(array $selector, array $map): void
    {
        foreach ($selector as $key => $value) {
            if (is_string($key) && ($key === '' || $key[0] !== '$') && !isset($map[$key])) {
                throw new UserError("Argument 'query' names an unknown field '" . substr($key, 0, 40) . "'");
            }
            if (is_array($value)) {
                $this->assertKnownFields($value, $map);
            }
        }
    }

    /**
     * @param int|string $id
     */
    private function readOrFail(Model $model, $id): void
    {
        if (!$model->read($id)) {
            throw new UserError($this->name . " id '$id' not found");
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
            if ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            } else {
                $pdo->rollBack();
            }
            throw $e;
        }
        if ($savepoint !== null) {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } else {
            $pdo->commit();
        }
        return $result;
    }
}
