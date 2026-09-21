<?php

namespace Anorm\GraphQL\Test\Fixtures\Type;

use Anorm\GraphQL\ModelType;
use Anorm\GraphQL\Test\Fixtures\Model\WidgetModel;
use Anorm\Model;
use DI\Container;
use GraphQL\Type\Definition\Type;
use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;

/** A hand-written ModelType that records its hooks and can be told to refuse. */
class RecordingWidgetType extends ModelType
{
    /** @var array<int, array<int, mixed>> [verb, model id or null] per authorize() call */
    public $authorized = [];

    /** @var array<int, array<int, mixed>> [name, isUpdate] per beforeWrite() call */
    public $written = [];

    /** @var string|null Refuse this verb */
    public $refuse = null;

    /** @var string|null Throw from beforeWrite() when the model has this name */
    public $failOnName = null;

    /** @var bool Run DDL in beforeWrite() and carry on as if nothing had happened */
    public $ddlInBeforeWrite = false;

    /** @var bool Run DDL just before failing: MySQL commits implicitly, taking any savepoint with it */
    public $ddlBeforeFailing = false;

    public function __construct()
    {
        parent::__construct(ObjectBuilder::create('WidgetType')->setFields($this->fields())->build());
    }

    protected function modelClass(): string
    {
        return WidgetModel::class;
    }

    protected function fields(): array
    {
        return [
            FieldBuilder::create('id', Type::nonNull(Type::id()))->build(),
            FieldBuilder::create('name', Type::string())->build(),
            FieldBuilder::create('quantity', Type::int())->build(),
        ];
    }

    protected function authorize(string $verb, ?Model $model, Container $context): void
    {
        /** @var WidgetModel|null $model */
        $this->authorized[] = [$verb, $model === null ? null : (int) $model->id];
        if ($verb === $this->refuse) {
            throw new \RuntimeException("refused $verb");
        }
    }

    protected function beforeWrite(Model $model, array $input, bool $isUpdate, Container $context): void
    {
        /** @var WidgetModel $model */
        $this->written[] = [$model->name, $isUpdate];
        if ($this->ddlInBeforeWrite) {
            $this->runDdl($model);
        }
        if ($this->failOnName !== null && $model->name === $this->failOnName) {
            if ($this->ddlBeforeFailing) {
                $this->runDdl($model);
            }
            throw new \RuntimeException('beforeWrite failed on ' . $model->name);
        }
    }

    /** Any DDL will do: MySQL commits implicitly, taking the transaction and its savepoints with it. */
    private function runDdl(Model $model): void
    {
        $model->getPdo()->exec('CREATE TABLE IF NOT EXISTS `agq_implicit_commit` (`id` INT)');
        $model->getPdo()->exec('DROP TABLE `agq_implicit_commit`');
    }
}
