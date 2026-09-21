<?php

namespace Anorm\GraphQL\Test\Tools;

use Anorm\GraphQL\Test\Fixtures\Model\LedgerLineModel;
use Anorm\GraphQL\Test\Fixtures\Model\WidgetModel;
use Anorm\GraphQL\Test\Fixtures\OtherModel\GroupModel;
use Anorm\GraphQL\Tools\NullPdo;
use Anorm\GraphQL\Tools\TypeInfoBuilder;
use PHPUnit\Framework\TestCase;

class TypeInfoBuilderTest extends TestCase
{
    public function testAModelCanBeConstructedWithoutADatabase(): void
    {
        $this->assertInstanceOf(WidgetModel::class, new WidgetModel(new NullPdo()));
    }

    public function testFieldsAndTheirTypes(): void
    {
        $info = (new TypeInfoBuilder())->build(new WidgetModel(new NullPdo()));
        $this->assertSame('Widget', $info->entity);
        $this->assertSame(WidgetModel::class, $info->modelClass);
        $this->assertSame('id', $info->keyProperty);
        $this->assertSame('widget', $info->fieldPrefix());
        $this->assertFalse($info->readOnly);
        $this->assertSame([
            'id' => 'ID',
            'name' => 'String',
            'quantity' => 'Int',
            'price' => 'Float',
            'active' => 'Boolean',
            'ownerId' => 'ID',
            'notes' => 'String',
        ], $info->fields);
    }

    public function testRelationshipAndArrayPropertiesAreNotFields(): void
    {
        $info = (new TypeInfoBuilder())->build(new GroupModel(new NullPdo()));
        $this->assertSame(['id' => 'ID', 'title' => 'String'], $info->fields, 'the key leads, whatever the declaration order');
    }

    public function testTheIdSuffixBeatsTheDeclaredTypeAndIsCaseSensitive(): void
    {
        $info = (new TypeInfoBuilder())->build(new WidgetModel(new NullPdo()));
        $this->assertSame('ID', $info->fields['ownerId'], 'declared int, but named like a foreign key');
        $this->assertSame('Float', $info->fields['price']);
    }

    public function testAModelWithoutASingleKeyIsSkippedWithAReason(): void
    {
        $builder = new TypeInfoBuilder();
        $this->assertNull($builder->build(new LedgerLineModel(new NullPdo())));
        $this->assertArrayHasKey(LedgerLineModel::class, $builder->skipped);
        $this->assertStringContainsString("'id' is not a property", $builder->skipped[LedgerLineModel::class]);
    }

    public function testEntityNameStripsOnlyATrailingSuffix(): void
    {
        $builder = new TypeInfoBuilder('Model');
        $this->assertSame('Client', $builder->entityName('App\Models\ClientModel'));
        $this->assertSame('Client', $builder->entityName('Client'));
        $this->assertSame('ModelRailway', $builder->entityName('ModelRailwayModel'));
        $this->assertSame('Model', $builder->entityName('Model'), 'never strips the whole name');
    }

    public function testReadOnlyIsCarried(): void
    {
        $this->assertTrue((new TypeInfoBuilder())->build(new WidgetModel(new NullPdo()), true)->readOnly);
    }
}
