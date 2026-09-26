<?php

namespace Anorm\GraphQL\Test\Fixtures\Type;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/** As a generated <Entity>CreateInput would be for an entity given --without-update: no key. */
class NoUpdateWidgetCreateInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct(ObjectBuilder::create('NoUpdateWidgetCreateInput')->setFields([
            FieldBuilder::create('name', Type::string())->build(),
        ])->build());
    }
}
