<?php

namespace Anorm\GraphQL\Test\Fixtures\Type;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/** The least a Type needs for GraphQLUtils to resolve a method on it. */
class ResolvingType extends ObjectType
{
    public function __construct()
    {
        parent::__construct(['name' => 'ResolvingType', 'fields' => ['id' => Type::id()]]);
    }

    public function resolveList()
    {
        return [];
    }
}
