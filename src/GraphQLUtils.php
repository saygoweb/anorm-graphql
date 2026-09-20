<?php

namespace Anorm\GraphQL;

use GraphQL\Type\Definition\Type;
use Anorm\GraphQL\Builder\FieldBuilder;

/**
 * General utility class for GraphQL
 */
class GraphQLUtils
{
    public static function methodResolver(Type $type, string $method, string $field): callable
    {
        $callable = [$type, $method];
        if (!is_callable($callable)) {
            $typeClass = get_class($type);
            throw new \Exception("Error creating field $field with method '$typeClass::$method'");
        }
        return $callable;
    }

    public static function createListField(string $name, Type $type, string $method, $isNullable = false)
    {
        if ($isNullable) {
            return FieldBuilder::create($name, Type::listOf(Type::nonNull($type)))
                ->setResolver(GraphQLUtils::methodResolver($type, $method, $name));
        }
        return FieldBuilder::create($name, Type::nonNull(Type::listOf(Type::nonNull($type))))
            ->setResolver(GraphQLUtils::methodResolver($type, $method, $name));
    }

    public static function createField(string $name, Type $type, string $method, $isNullable = false)
    {
        if ($isNullable) {
            return FieldBuilder::create($name, $type)
                ->setResolver(GraphQLUtils::methodResolver($type, $method, $name));
        }
        return FieldBuilder::create($name, Type::nonNull($type))
            ->setResolver(GraphQLUtils::methodResolver($type, $method, $name));
    }
}
