<?php

namespace Anorm\GraphQL\Test\Fixtures\Type;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

class LegacyWidgetInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct(ObjectBuilder::create('LegacyWidgetInput')->setFields([
            FieldBuilder::create('id', Type::id())->build(),
            FieldBuilder::create('name', Type::string())->build(),
        ])->build());
    }
}
