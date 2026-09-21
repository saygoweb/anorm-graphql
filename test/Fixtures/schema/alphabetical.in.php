<?php

namespace App\GraphQL;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Type\MangoInput;
use App\GraphQL\Type\Zebra\ZebraType;
use DI\Container;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;

class ApiSchema extends Schema
{
    public $context;

    public function __construct(Container $context)
    {
        $this->context = $context;
        $object = [
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    GraphQLUtils::createListField('appleList', $this->type(AppleType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // A hand-written list that anorm-graphql must leave alone.
                    GraphQLUtils::createListField('clientList', $this->type(HandClientType::class), 'resolveList')
                        ->build(),
                    GraphQLUtils::createListField('zebraList', $this->type(ZebraType::class), 'resolveList')
                        ->build()
                ]
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => [],
            ]),
        ];
        parent::__construct($object);
    }

    private function type(string $name)
    {
        return $this->context->get($name);
    }
}
