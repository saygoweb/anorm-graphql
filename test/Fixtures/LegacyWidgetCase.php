<?php

namespace Anorm\GraphQL\Test\Fixtures;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Test\Fixtures\Type\LegacyWidgetInput;
use Anorm\GraphQL\Test\Fixtures\Type\LegacyWidgetType;
use Anorm\GraphQL\Test\TestEnvironment;
use Anorm\GraphQL\Testing\ModelTypeTestCase;
use Anorm\GraphQL\Type\MangoInput;
use DI\Container;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * A project's test of a Type over a MyISAM table, as a generated test would be. Not
 * named *Test, so PHPUnit does not collect it: ModelTypeTestCaseTest runs it by hand.
 */
class LegacyWidgetCase extends ModelTypeTestCase
{
    /** @var bool What allowNonTransactionalTables() answers */
    public static $allow = false;

    protected function createContainer(): Container
    {
        return TestEnvironment::container();
    }

    protected function createSchema(Container $container): Schema
    {
        $type = $container->get(LegacyWidgetType::class);
        $input = $container->get(LegacyWidgetInput::class);
        return new Schema([
            'query' => new ObjectType(['name' => 'Query', 'fields' => [
                GraphQLUtils::createListField('legacyWidgetList', $type, 'resolveList')
                    ->addArgument('query', $container->get(MangoInput::class))->build(),
            ]]),
            'mutation' => new ObjectType(['name' => 'Mutation', 'fields' => [
                GraphQLUtils::createListField('legacyWidgetUpsert', $type, 'resolveUpsert')
                    ->addArgument('input', Type::nonNull(Type::listOf(Type::nonNull($input))))->build(),
                GraphQLUtils::createListField('legacyWidgetDelete', $type, 'resolveDelete')
                    ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))->build(),
            ]]),
        ]);
    }

    protected function typeClass(): string
    {
        return LegacyWidgetType::class;
    }

    protected function inputClass(): ?string
    {
        return LegacyWidgetInput::class;
    }

    protected function entityName(): string
    {
        return 'legacyWidget';
    }

    protected function expectedFieldTypes(): array
    {
        return ['id' => 'ID!', 'name' => 'String'];
    }

    protected function sampleInput(): array
    {
        return ['name' => 'name 1'];
    }

    protected function sampleUpdate(): array
    {
        return ['name' => 'name 2'];
    }

    /** As a project might add to its own, once-only test file: a write that bypasses testLifecycle(). */
    public function testAWriteOfTheProjectsOwn(): void
    {
        $rows = $this->upsert([['name' => 'written by the project']]);
        $this->assertCount(1, $rows);
    }

    /**
     * The same, written the way people write GraphQL by hand, and saved the way some
     * editors save it: a byte order mark, a comment, a fragment, then a named operation.
     */
    public function testAWriteBehindACommentAndAName(): void
    {
        $document = "\xEF\xBB\xBF# create a legacy widget\n\nfragment Bits on LegacyWidgetType { id name }\n\n"
            . 'mutation CreateOne($input: [LegacyWidgetInput!]!) { legacyWidgetUpsert(input: $input) { ...Bits } }';
        $data = $this->execute($document, ['input' => [['name' => 'behind a comment']]]);
        $this->assertCount(1, $data['legacyWidgetUpsert']);
    }

    protected function allowNonTransactionalTables(): bool
    {
        return self::$allow;
    }
}
