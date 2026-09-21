<?php

namespace App\GraphQL;

use Anorm\GraphQL\GraphQLUtils;
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
                    GraphQLUtils::createListField('clientList', $this->type(ClientType::class), 'anOldShape')
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('goneList', $this->type(GoneType::class), 'resolveList')
                        ->build(),
                ],
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => [
                    // The marker was removed from this one, so it is the project's now.
                    GraphQLUtils::createListField('clientUpsert', $this->type(ClientType::class), 'resolveMyOwnUpsert')
                        ->build(),
                ],
            ]),
        ]);
    }
}
