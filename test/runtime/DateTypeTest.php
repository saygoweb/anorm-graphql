<?php

namespace Anorm\GraphQL\Test\Runtime;

use Anorm\GraphQL\Type\DateType;
use DI\Container;
use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\StringValueNode;
use PHPUnit\Framework\TestCase;

class DateTypeTest extends TestCase
{
    public function testOneInstanceNamedDate(): void
    {
        $this->assertSame(DateType::instance(), DateType::instance());
        $this->assertSame('Date', DateType::instance()->name);
        DateType::instance()->assertValid();
    }

    /**
     * The checklist requires one `Date` instance: nothing may build a second one,
     * not `new DateType(...)` and not a container asked for it by class name.
     */
    public function testNoOtherRouteCanBuildASecondInstance(): void
    {
        $this->assertFalse(
            (new \ReflectionClass(DateType::class))->isInstantiable(),
            'a private constructor is the only way a container route can be refused'
        );
        $this->expectException(\Throwable::class);
        (new Container())->get(DateType::class);
    }

    public function testSerialisesDatesAndDateStrings(): void
    {
        $type = DateType::instance();
        $this->assertSame('2026-03-04', $type->serialize(new \DateTime('2026-03-04 13:14:15')));
        $this->assertSame('2026-03-04', $type->serialize(new \DateTimeImmutable('2026-03-04')));
        $this->assertSame('2026-03-04', $type->serialize('2026-03-04'));
        $this->assertSame('2026-03-04', $type->serialize('2026-03-04 00:00:00'));
        $this->assertNull($type->serialize('0000-00-00'), "MySQL's zero date is no date");
        $this->assertNull($type->serialize('0000-00-00 00:00:00'));
        // A zero date read through a model with a date transformer arrives as a
        // \DateTime rolled back to year 0 (-0001-11-30), not as the string '0000-00-00'.
        $this->assertNull($type->serialize(new \DateTime('0000-00-00')), 'a rolled-over zero date is still no date');
        $this->assertNull($type->serialize(new \DateTimeImmutable('0000-00-00 00:00:00')));
    }

    /**
     * @dataProvider unserialisable
     * @param mixed $value
     */
    public function testRefusesToSerialiseWhatIsNotADate($value): void
    {
        $this->expectException(SerializationError::class);
        DateType::instance()->serialize($value);
    }

    /** @return array<string, array<int, mixed>> */
    public function unserialisable(): array
    {
        return [
            'text' => ['tomorrow'],
            'impossible date' => ['2026-02-30'],
            'integer' => [20260304],
            'array' => [['2026-03-04']],
        ];
    }

    public function testParsesAnIsoDateToMidnightUtc(): void
    {
        $date = DateType::instance()->parseValue('2026-03-04');
        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2026-03-04 00:00:00 UTC', $date->format('Y-m-d H:i:s T'));
        $literal = DateType::instance()->parseLiteral(new StringValueNode(['value' => '2024-02-29']));
        $this->assertSame('2024-02-29', $literal->format('Y-m-d'));
    }

    /**
     * @dataProvider unparseable
     * @param mixed $value
     */
    public function testRefusesAnythingButAnIsoCalendarDate($value): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Date must be a date as YYYY-MM-DD');
        DateType::instance()->parseValue($value);
    }

    /** @return array<string, array<int, mixed>> */
    public function unparseable(): array
    {
        return [
            'not a leap year' => ['2026-02-29'],
            'no zero padding' => ['2026-3-4'],
            'with a time' => ['2026-03-04 10:00:00'],
            'user format' => ['04/03/2026'],
            'trailing newline' => ["2026-03-04\n"],
            'zero date' => ['0000-00-00'],
            'integer' => [20260304],
            'null' => [null],
        ];
    }

    public function testALiteralThatIsNotAStringIsRefused(): void
    {
        $this->expectException(Error::class);
        DateType::instance()->parseLiteral(new IntValueNode(['value' => '20260304']));
    }
}
