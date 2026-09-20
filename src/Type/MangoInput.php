<?php

namespace Anorm\GraphQL\Type;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;
use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;

// See https://github.com/cloudant/mango
class MangoInput extends InputObjectType
{
    public function __construct()
    {
        $object = ObjectBuilder::create('MangoInput')->setFields([
            FieldBuilder::create('action', Type::string())->build(),
            FieldBuilder::create('selector', Type::string())->build(),
            FieldBuilder::create('limit', Type::int())->build(),
            FieldBuilder::create('skip', Type::int())->build(),
            FieldBuilder::create('sort', Type::listOf(Type::string()))->build(),
        ]);
        parent::__construct($object->build());
    }
}
