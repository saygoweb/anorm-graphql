<?php

namespace App\GraphQL;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Type\MangoInput;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

class ApiSchema extends Schema
{
    public function __construct($context)
    {
        parent::__construct([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [

                    // anorm-graphql
                    GraphQLUtils::createListField('clientList', $this->type(\App\GraphQL\Type\Client\ClientType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('goneList', $this->type(GoneType::class), 'resolveList')
                        ->build(),
                ],
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => [
                    // anorm-graphql
                    GraphQLUtils::createListField('clientDelete', $this->type(\App\GraphQL\Type\Client\ClientType::class), 'resolveDelete')
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
                    // The marker was removed from this one, so it is the project's now.
                    GraphQLUtils::createListField('clientUpsert', $this->type(ClientType::class), 'resolveMyOwnUpsert')
                        ->build(),
                ],
            ]),
        ]);
    }
}
