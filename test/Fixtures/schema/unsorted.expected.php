<?php

namespace App\GraphQL;

use Anorm\GraphQL\Type\MangoInput;
use App\GraphQL\Type\Mango\MangoType;
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
					\Anorm\GraphQL\GraphQLUtils::createListField('mangoList', $this->type(MangoType::class), 'resolveList')
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
					\Anorm\GraphQL\GraphQLUtils::createListField('mangoDelete', $this->type(MangoType::class), 'resolveDelete')
					    ->addArgument(
					        'id',
					        \GraphQL\Type\Definition\Type::nonNull(\GraphQL\Type\Definition\Type::listOf(\GraphQL\Type\Definition\Type::nonNull(\GraphQL\Type\Definition\Type::id())))
					    )
					    ->build(),
					// anorm-graphql
					\Anorm\GraphQL\GraphQLUtils::createListField('mangoUpsert', $this->type(MangoType::class), 'resolveUpsert')
					    ->addArgument(
					        'input',
					        \GraphQL\Type\Definition\Type::nonNull(\GraphQL\Type\Definition\Type::listOf(\GraphQL\Type\Definition\Type::nonNull($this->type(\App\GraphQL\Type\Mango\MangoInput::class))))
					    )
					    ->build(),
				],
			]),
		]);
	}
}
