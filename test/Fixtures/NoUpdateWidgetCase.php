<?php

namespace Anorm\GraphQL\Test\Fixtures;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Test\Fixtures\Type\NoUpdateWidgetCreateInput;
use Anorm\GraphQL\Test\Fixtures\Type\RecordingWidgetType;
use Anorm\GraphQL\Test\TestEnvironment;
use Anorm\GraphQL\Testing\ModelTypeTestCase;
use Anorm\GraphQL\Type\MangoInput;
use DI\Container;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * As a generated create-update test would be for an entity given --without-update:
 * widgetCreate and widgetDelete, no widgetUpdate. Not named *Test; run by hand.
 */
class NoUpdateWidgetCase extends ModelTypeTestCase
{
    protected function createContainer(): Container
    {
        return TestEnvironment::container();
    }

    protected function createSchema(Container $container): Schema
    {
        $type = $container->get(RecordingWidgetType::class);
        $input = $container->get(NoUpdateWidgetCreateInput::class);
        return new Schema([
            'query' => new ObjectType(['name' => 'Query', 'fields' => [
                GraphQLUtils::createListField('widgetList', $type, 'resolveList')
                    ->addArgument('query', $container->get(MangoInput::class))->build(),
            ]]),
            'mutation' => new ObjectType(['name' => 'Mutation', 'fields' => [
                GraphQLUtils::createListField('widgetCreate', $type, 'resolveCreate')
                    ->addArgument('input', Type::nonNull(Type::listOf(Type::nonNull($input))))->build(),
                GraphQLUtils::createListField('widgetDelete', $type, 'resolveDelete')
                    ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))->build(),
            ]]),
        ]);
    }

    protected function typeClass(): string
    {
        return RecordingWidgetType::class;
    }

    protected function inputClass(): ?string
    {
        return NoUpdateWidgetCreateInput::class;
    }

    protected function usesCreateMutation(): bool
    {
        return true;
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
        return ['name' => 'no-update 1'];
    }

    // sampleUpdate() is deliberately left at its default (empty): this entity has
    // no update mutation at all, so there is nothing to exercise even if it were set.
}
