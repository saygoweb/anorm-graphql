<?php

namespace App\GraphQL;

use GraphQL\Type\Schema;

class ApiSchema extends Schema
{
	public function __construct($context)
	{
		parent::__construct([
			'query' => new ObjectType([
				'name' => 'Query',
				'fields' => [
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
				],
			]),
		]);
	}
}
