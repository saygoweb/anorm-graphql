<?php

namespace App\GraphQL;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Type\MangoInput;
use App\GraphQL\Type\Banana\BananaInput;
use App\GraphQL\Type\Banana\BananaType;
use App\GraphQL\Type\Client\ClientInput;
use App\GraphQL\Type\Client\ClientType;
use App\GraphQL\Type\Zebra\ZebraType;
use App\GraphQL\Type\Zulu\ZuluType;
use DI\Container;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
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
                    // anorm-graphql
                    GraphQLUtils::createListField('bananaList', $this->type(BananaType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                    // A hand-written list that anorm-graphql must leave alone.
                    GraphQLUtils::createListField('clientList', $this->type(HandClientType::class), 'resolveList')
                        ->build(),
                    GraphQLUtils::createListField('zebraList', $this->type(ZebraType::class), 'resolveList')
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('zuluList', $this->type(ZuluType::class), 'resolveList')
                        ->addArgument('query', $this->type(MangoInput::class))
                        ->build(),
                ]
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => [
                    // anorm-graphql
                    GraphQLUtils::createListField('bananaDelete', $this->type(BananaType::class), 'resolveDelete')
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('bananaUpsert', $this->type(BananaType::class), 'resolveUpsert')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(BananaInput::class))))
                        )
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('clientDelete', $this->type(ClientType::class), 'resolveDelete')
                        ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
                        ->build(),
                    // anorm-graphql
                    GraphQLUtils::createListField('clientUpsert', $this->type(ClientType::class), 'resolveUpsert')
                        ->addArgument(
                            'input',
                            Type::nonNull(Type::listOf(Type::nonNull($this->type(ClientInput::class))))
                        )
                        ->build(),
                ],
            ]),
        ];
        parent::__construct($object);
    }

    private function type(string $name)
    {
        return $this->context->get($name);
    }
}
