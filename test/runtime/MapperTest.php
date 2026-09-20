<?php

namespace Anorm\GraphQL\Test\Runtime;

use Anorm\GraphQL\Mapper;
use PHPUnit\Framework\TestCase;

class MapperTest extends TestCase
{
    private function model(): object
    {
        $model = new \stdClass();
        $model->id = 7;
        $model->name = 'seven';
        $model->quantity = null;
        $model->_mapper = 'infrastructure';
        return $model;
    }

    public function testToArrayKeepsNullAsNull(): void
    {
        $row = Mapper::toArray($this->model());
        $this->assertArrayHasKey('quantity', $row);
        $this->assertNull($row['quantity'], "null must not become '': graphql-php's Int cannot serialise ''");
    }

    public function testToArraySkipsUnderscoredAndExcluded(): void
    {
        $this->assertSame(['id' => 7, 'quantity' => null], Mapper::toArray($this->model(), ['name']));
    }

    public function testToModelSetsOnlyKeysThatArePresent(): void
    {
        $model = $this->model();
        Mapper::toModel($model, ['name' => 'eight', 'quantity' => 3, 'unknown' => 'x', '_mapper' => 'hacked']);
        $this->assertSame('eight', $model->name);
        $this->assertSame(3, $model->quantity);
        $this->assertSame(7, $model->id, 'a key absent from the input is left alone');
        $this->assertSame('infrastructure', $model->_mapper);
        $this->assertFalse(property_exists($model, 'unknown'));
    }

    public function testToModelCanSetNullAndHonoursExclude(): void
    {
        $model = $this->model();
        Mapper::toModel($model, ['id' => 99, 'name' => null], ['id']);
        $this->assertSame(7, $model->id);
        $this->assertNull($model->name);
    }
}
