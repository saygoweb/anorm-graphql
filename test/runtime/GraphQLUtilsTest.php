<?php

namespace Anorm\GraphQL\Test\Runtime;

use Anorm\GraphQL\GraphQLUtils;
use Anorm\GraphQL\Test\Fixtures\Type\ResolvingType;
use Anorm\GraphQL\Type\MangoInput;
use PHPUnit\Framework\TestCase;

class GraphQLUtilsTest extends TestCase
{
    public function testCreateListFieldIsANonNullListOfNonNull(): void
    {
        $type = new ResolvingType();
        $field = GraphQLUtils::createListField('things', $type, 'resolveList')->build();
        $this->assertSame('things', $field['name']);
        $this->assertSame('[ResolvingType!]!', (string) $field['type']);
        $this->assertSame([$type, 'resolveList'], $field['resolve']);
    }

    public function testNullableVariants(): void
    {
        $type = new ResolvingType();
        $this->assertSame('[ResolvingType!]', (string) GraphQLUtils::createListField('a', $type, 'resolveList', true)->build()['type']);
        $this->assertSame('ResolvingType', (string) GraphQLUtils::createField('b', $type, 'resolveList', true)->build()['type']);
        $this->assertSame('ResolvingType!', (string) GraphQLUtils::createField('c', $type, 'resolveList')->build()['type']);
    }

    public function testAMissingResolverMethodIsReportedWhenTheSchemaIsBuilt(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("ResolvingType::resolveNothing");
        GraphQLUtils::createField('broken', new ResolvingType(), 'resolveNothing');
    }

    public function testMangoInputShape(): void
    {
        $fields = [];
        foreach ((new MangoInput())->getFields() as $name => $field) {
            $fields[$name] = (string) $field->getType();
        }
        $this->assertSame(
            ['action' => 'String', 'selector' => 'String', 'limit' => 'Int', 'skip' => 'Int', 'sort' => '[String]'],
            $fields
        );
    }
}
