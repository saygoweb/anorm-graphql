<?php

namespace App\GraphQL;

use GraphQL\Type\Schema;

class ApiSchema extends Schema
{
    public function __construct($context)
    {
        parent::__construct([
            'query' => new ObjectType(['name' => 'Query', 'fields' => $this->queryFields()]),
            'mutation' => new ObjectType(['name' => 'Mutation', 'fields' => []]),
        ]);
    }
}
