<?php

namespace Anorm\GraphQL\Test\Tools;

use Anorm\GraphQL\Test\Fixtures\CalendarModel\EventModel;
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

    public function testARequiredPropertyIsNamedAndTheKeyNeverIs(): void
    {
        $info = (new TypeInfoBuilder())->build(new EventModel(new NullPdo()));
        $this->assertSame(['title'], $info->required);
        $this->assertSame('upsert', $info->mutations, 'the default');
        $this->assertSame([], (new TypeInfoBuilder())->build(new WidgetModel(new NullPdo()))->required);
    }

    public function testADateTimePropertyIsADate(): void
    {
        $info = (new TypeInfoBuilder())->build(new EventModel(new NullPdo()));
        $this->assertSame(['id' => 'ID', 'title' => 'String', 'notes' => 'String', 'dueOn' => 'Date'], $info->fields);
    }

    /**
     * @dataProvider dateDeclarations
     */
    public function testEveryDateTimeInterfaceDeclarationIsADate(string $declaration, string $expected): void
    {
        $class = 'DateProbe' . md5($declaration);
        eval("namespace Anorm\\GraphQL\\Test\\Tools; class $class extends \\Anorm\\Model {
            public function __construct(\\PDO \$pdo) {
                parent::__construct(\$pdo, \\Anorm\\DataMapper::create(\$pdo, 'probes', \\Anorm\\DataMapper::autoMap(\$this)));
            }
            /** @var int */ public \$id;
            /** @var $declaration */ public \$when;
        }");
        $fqcn = __NAMESPACE__ . '\\' . $class;
        $info = (new TypeInfoBuilder())->build(new $fqcn(new NullPdo()));
        $this->assertSame($expected, $info->fields['when'], $declaration);
    }

    /** @return array<string, array<int, string>> */
    public function dateDeclarations(): array
    {
        return [
            'DateTime' => ['\DateTime', 'Date'],
            'DateTimeImmutable' => ['\DateTimeImmutable', 'Date'],
            'DateTimeInterface' => ['\DateTimeInterface', 'Date'],
            'nullable' => ['\DateTime|null', 'Date'],
            'another class' => ['\ArrayObject', 'String'],
            'string' => ['string', 'String'],
        ];
    }

    /**
     * A native PHP type is enforced at assignment; parseValue() always hands back a
     * \DateTimeImmutable. A property natively typed \DateTime cannot take one (they
     * are unrelated classes), so it must not be generated as Date — that would throw
     * a TypeError on every create or update. \DateTimeImmutable and \DateTimeInterface
     * are both satisfied by a \DateTimeImmutable, so they stay Date. An @var-only
     * declaration (see dateDeclarations() above) is never enforced, so it is unaffected.
     *
     * @dataProvider typedDateDeclarations
     */
    public function testANativelyTypedPropertyIsADateOnlyWhenADateTimeImmutableSatisfiesIt(
        string $declaration,
        string $expected
    ): void {
        $class = 'TypedDateProbe' . md5($declaration);
        eval("namespace Anorm\\GraphQL\\Test\\Tools; class $class extends \\Anorm\\Model {
            public function __construct(\\PDO \$pdo) {
                parent::__construct(\$pdo, \\Anorm\\DataMapper::create(\$pdo, 'probes', \\Anorm\\DataMapper::autoMap(\$this)));
            }
            /** @var int */ public \$id;
            public $declaration \$when;
        }");
        $fqcn = __NAMESPACE__ . '\\' . $class;
        $info = (new TypeInfoBuilder())->build(new $fqcn(new NullPdo()));
        $this->assertSame($expected, $info->fields['when'], $declaration);
    }

    /** @return array<string, array<int, string>> */
    public function typedDateDeclarations(): array
    {
        return [
            'typed DateTime' => ['\DateTime', 'String'],
            'typed DateTimeImmutable' => ['\DateTimeImmutable', 'Date'],
            'typed DateTimeInterface' => ['\DateTimeInterface', 'Date'],
            'typed nullable DateTime' => ['?\DateTime', 'String'],
        ];
    }
}
