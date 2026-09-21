<?php

namespace Anorm\GraphQL\Test\Fixtures\Type;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use Anorm\GraphQL\ModelType;
use Anorm\GraphQL\Test\Fixtures\OtherModel\LegacyWidgetModel;
use GraphQL\Type\Definition\Type;

class LegacyWidgetType extends ModelType
{
    public function __construct()
    {
        parent::__construct(ObjectBuilder::create('LegacyWidgetType')->setFields($this->fields())->build());
    }

    protected function modelClass(): string
    {
        return LegacyWidgetModel::class;
    }

    protected function fields(): array
    {
        return [
            FieldBuilder::create('id', Type::nonNull(Type::id()))->build(),
            FieldBuilder::create('name', Type::string())->build(),
        ];
    }
}
