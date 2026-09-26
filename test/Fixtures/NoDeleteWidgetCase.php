<?php

namespace Anorm\GraphQL\Test\Fixtures;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Test\Fixtures\Type\NoDeleteWidgetInput;
use Anorm\GraphQL\Test\Fixtures\Type\RecordingWidgetType;
use Anorm\GraphQL\Test\TestEnvironment;
use Anorm\GraphQL\Testing\ModelTypeTestCase;
use Anorm\GraphQL\Type\MangoInput;
use DI\Container;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * As a generated upsert-mode test would be for an entity given --without-delete:
 * widgetUpsert only, no widgetDelete. Not named *Test; run by hand.
 */
class NoDeleteWidgetCase extends ModelTypeTestCase
{
    protected function createContainer(): Container
    {
        return TestEnvironment::container();
    }

    protected function createSchema(Container $container): Schema
    {
        $type = $container->get(RecordingWidgetType::class);
        $input = $container->get(NoDeleteWidgetInput::class);
        return new Schema([
            'query' => new ObjectType(['name' => 'Query', 'fields' => [
                GraphQLUtils::createListField('widgetList', $type, 'resolveList')
                    ->addArgument('query', $container->get(MangoInput::class))->build(),
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
        return NoDeleteWidgetInput::class;
    }

    protected function hasDeleteMutation(): bool
    {
        return false;
    }

    protected function entityName(): string
    {
        return 'widget';
    }

    protected function expectedFieldTypes(): array
    {
        return ['id' => 'ID!', 'name' => 'String'];
    }

    protected function sampleInput(): array
    {
        return ['name' => 'no-delete 1'];
    }

    protected function sampleUpdate(): array
    {
        return ['name' => 'no-delete 2'];
    }
}
