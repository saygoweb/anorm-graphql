<?php

namespace Anorm\GraphQL\Test\Fixtures;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Test\Fixtures\Type\RecordingWidgetType;
use Anorm\GraphQL\Test\TestEnvironment;
use Anorm\GraphQL\Testing\ModelTypeTestCase;
use DI\Container;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * A project's test of a Type over an ordinary InnoDB table, with a write of the
 * project's own that never calls useDatabase(). Not named *Test: run by hand.
 */
class PlainWidgetCase extends ModelTypeTestCase
{
    protected function createContainer(): Container
    {
        return TestEnvironment::container();
    }

    protected function createSchema(Container $container): Schema
    {
        $type = $container->get(RecordingWidgetType::class);
        $input = new InputObjectType(ObjectBuilder::create('WidgetInput')->setFields([
            FieldBuilder::create('id', Type::id())->build(),
            FieldBuilder::create('name', Type::string())->build(),
        ])->build());
        return new Schema([
            'query' => new ObjectType(['name' => 'Query', 'fields' => [
                GraphQLUtils::createListField('widgetList', $type, 'resolveList')->build(),
            ]]),
            'mutation' => new ObjectType(['name' => 'Mutation', 'fields' => [
                GraphQLUtils::createListField('widgetUpsert', $type, 'resolveUpsert')
                    ->addArgument('input', Type::nonNull(Type::listOf(Type::nonNull($input))))->build(),
            ]]),
        ]);
    }

    protected function typeClass(): string
    {
        return RecordingWidgetType::class;
    }

    protected function inputClass(): ?string
    {
        return null;
    }

    protected function entityName(): string
    {
        return 'widget';
    }

    protected function expectedFieldTypes(): array
    {
        return ['id' => 'ID!', 'name' => 'String'];
    }

    public function testAWriteOfTheProjectsOwn(): void
    {
        $data = $this->execute(
            'mutation ($input: [WidgetInput!]!) { widgetUpsert(input: $input) { id name } }',
            ['input' => [['name' => 'written by the project']]]
        );
        $this->assertSame('written by the project', $data['widgetUpsert'][0]['name']);
    }
}
