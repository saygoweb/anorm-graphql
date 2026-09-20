<?php

namespace Anorm\GraphQL\Test\Runtime;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    public function testAFieldIsNameAndTypeUntilMoreIsAsked(): void
    {
        $this->assertSame(['name' => 'id', 'type' => Type::id()], FieldBuilder::create('id', Type::id())->build());
    }

    public function testEverythingAFieldCanCarry(): void
    {
        $resolver = function () {
            return 1;
        };
        $field = FieldBuilder::create('things', Type::int())
            ->setDescription('How many')
            ->setDeprecationReason('Use thingList')
            ->addArgument('plain', Type::string())
            ->addArgument('described', Type::int(), 'A number')
            ->addArgument('defaulted', Type::boolean(), null, false)
            ->addArgument('nullDefault', Type::string(), null, null)
            ->setResolver($resolver)
            ->build();

        $this->assertSame('How many', $field['description']);
        $this->assertSame('Use thingList', $field['deprecationReason']);
        $this->assertSame($resolver, $field['resolve']);
        $this->assertSame(['type' => Type::string()], $field['args']['plain']);
        $this->assertSame(['type' => Type::int(), 'description' => 'A number'], $field['args']['described']);
        $this->assertSame(['type' => Type::boolean(), 'defaultValue' => false], $field['args']['defaulted']);
        $this->assertArrayHasKey('defaultValue', $field['args']['nullDefault'], 'an explicit null default is still a default');
        $this->assertArrayNotHasKey('defaultValue', $field['args']['plain']);
    }

    public function testGraphqlPhpAcceptsWhatTheBuildersProduce(): void
    {
        $config = ObjectBuilder::create('Thing')
            ->setDescription('A thing')
            ->setFields([
                FieldBuilder::create('id', Type::nonNull(Type::id()))->build(),
                FieldBuilder::create('size', Type::int())->addArgument('unit', Type::string(), null, 'cm')->build(),
            ])
            ->build();

        $object = new ObjectType($config);
        $object->assertValid();
        $this->assertSame('A thing', $object->description);
        $this->assertSame('ID!', (string) $object->getField('id')->getType());
        $this->assertSame('cm', $object->getField('size')->getArg('unit')->defaultValue);

        $input = new InputObjectType(ObjectBuilder::create('ThingInput')->setFields([
            FieldBuilder::create('size', Type::int())->build(),
        ])->build());
        $this->assertSame('Int', (string) $input->getField('size')->getType());
    }
}
