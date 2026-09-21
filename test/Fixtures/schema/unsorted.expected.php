<?php

namespace App\GraphQL;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Type\MangoInput;
use App\GraphQL\Type\Mango\MangoInput;
use App\GraphQL\Type\Mango\MangoType;
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
					GraphQLUtils::createListField('mangoList', $this->type(MangoType::class), 'resolveList')
					    ->addArgument('query', $this->type(MangoInput::class))
					    ->build(),
					GraphQLUtils::createListField('zebraList', $this->type(ZebraType::class), 'resolveList')->build(),
					GraphQLUtils::createListField('appleList', $this->type(AppleType::class), 'resolveList')->build(),
				],
			]),
			'mutation' => new ObjectType([
				'name' => 'Mutation',
				'fields' => [
					FieldBuilder::create('login', Type::boolean())
						->addArgument('options', ['a', 'b'])
						->setResolver(function ($root, $args) {
							return [$args['x'], "brackets ] and , in a string"];
						})
						->build(),
					// anorm-graphql
					GraphQLUtils::createListField('mangoDelete', $this->type(MangoType::class), 'resolveDelete')
					    ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))
					    ->build(),
					// anorm-graphql
					GraphQLUtils::createListField('mangoUpsert', $this->type(MangoType::class), 'resolveUpsert')
					    ->addArgument('input', Type::nonNull(Type::listOf(Type::nonNull($this->type(MangoInput::class)))))
					    ->build(),
				],
			]),
		]);
	}
}
