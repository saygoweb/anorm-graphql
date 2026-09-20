<?php

namespace Anorm\GraphQL\Test\Integration;

use Anorm\GraphQL\Test\Fixtures\Type\RecordingWidgetType;
use Anorm\GraphQL\Test\TestEnvironment;
use DI\Container;
use GraphQL\Error\UserError;
use PHPUnit\Framework\TestCase;

class ModelTypeTest extends TestCase
{
    /** @var RecordingWidgetType */
    private $type;

    /** @var Container */
    private $context;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::createTables();
    }

    protected function setUp(): void
    {
        TestEnvironment::pdo()->exec('DELETE FROM `widgets`');
        $this->type = new RecordingWidgetType();
        $this->context = TestEnvironment::container();
    }

    protected function tearDown(): void
    {
        if (TestEnvironment::pdo()->inTransaction()) {
            TestEnvironment::pdo()->rollBack();
        }
    }

    private function rowCount(): int
    {
        return (int) TestEnvironment::pdo()->query('SELECT COUNT(*) FROM `widgets`')->fetchColumn();
    }

    /**
     * @param array<int, array<string, mixed>> $inputs
     * @return array<int, array<string, mixed>>
     */
    private function upsert(array $inputs): array
    {
        return $this->type->resolveUpsert(null, ['input' => $inputs], $this->context);
    }

    /**
     * @param array<string, mixed> $query
     * @return string[] The names, in the order returned
     */
    private function names(array $query): array
    {
        return array_column($this->type->resolveList(null, ['query' => $query], $this->context), 'name');
    }

    public function testListOfAnEmptyTableIsEmpty(): void
    {
        $this->assertSame([], $this->type->resolveList(null, [], $this->context));
    }

    public function testUpsertWithoutAKeyCreatesAndReturnsTheRows(): void
    {
        $rows = $this->upsert([['name' => 'a', 'quantity' => 1], ['name' => 'b', 'quantity' => 2]]);
        $this->assertCount(2, $rows);
        $this->assertNotEmpty($rows[0]['id']);
        $this->assertNotEquals($rows[0]['id'], $rows[1]['id']);
        $this->assertSame('a', $rows[0]['name']);
        $this->assertNull($rows[0]['price'], 'an unset column is null, not an empty string');
        $this->assertSame(2, $this->rowCount());
    }

    public function testAnInputKeyIsIgnoredOnCreateWhenEmpty(): void
    {
        $rows = $this->upsert([['id' => '', 'name' => 'a'], ['id' => null, 'name' => 'b']]);
        $this->assertCount(2, $rows);
        $this->assertSame(2, $this->rowCount());
    }

    public function testUpsertWithAKeyUpdatesInPlace(): void
    {
        $id = $this->upsert([['name' => 'a', 'quantity' => 1]])[0]['id'];
        $rows = $this->upsert([['id' => $id, 'quantity' => 5]]);
        $this->assertEquals($id, $rows[0]['id']);
        $this->assertEquals(5, $rows[0]['quantity']);
        $this->assertSame('a', $rows[0]['name'], 'a field absent from the input is left alone');
        $this->assertSame(1, $this->rowCount());
    }

    public function testUpsertOfAnUnknownKeyIsAClientSafeError(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage("WidgetType id '999999' not found");
        $this->upsert([['id' => 999999, 'name' => 'x']]);
    }

    public function testMangoSelectorLimitSkipAndSort(): void
    {
        $this->upsert([
            ['name' => 'a', 'quantity' => 1],
            ['name' => 'b', 'quantity' => 2],
            ['name' => 'c', 'quantity' => 3],
        ]);
        $this->assertSame(['b'], $this->names(['selector' => '{"name": "b"}']));
        $this->assertSame(['b', 'c'], $this->names(['selector' => '{"quantity": {"$gt": 1}}', 'sort' => ['name']]));
        $this->assertSame(['a', 'b'], $this->names(['sort' => ['name'], 'limit' => 2]));
        $this->assertSame(['b', 'c'], $this->names(['sort' => ['name'], 'limit' => 2, 'skip' => 1]));
        $this->assertCount(3, $this->type->resolveList(null, ['query' => null], $this->context));
    }

    public function testASelectorThatIsNotJsonIsAClientSafeError(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage("query.selector");
        $this->names(['selector' => '{not json']);
    }

    /**
     * @dataProvider hostileQueries
     * @param array<string, mixed> $query
     */
    public function testAFieldNameThatIsNotAPropertyNeverReachesTheSql(array $query): void
    {
        $this->upsert([['name' => 'a']]);
        try {
            $this->names($query);
            $this->fail('expected the unknown field to be refused');
        } catch (UserError $e) {
            $this->assertStringContainsString('unknown field', $e->getMessage());
        }
        $this->assertSame(1, $this->rowCount());
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function hostileQueries(): array
    {
        $hostile = 'id` = 1 OR 1=1; DROP TABLE widgets; -- ';
        return [
            'selector key' => [['selector' => json_encode([$hostile => 1])]],
            'nested in $or' => [['selector' => json_encode(['$or' => [['name' => 'a'], [$hostile => 1]]])]],
            'sort string' => [['sort' => [$hostile]]],
            'a column name is not a property name' => [['selector' => '{"owner_id": 1}']],
        ];
    }

    public function testOperatorsAndNestedSelectorsStillWork(): void
    {
        $this->upsert([['name' => 'a', 'quantity' => 1], ['name' => 'b', 'quantity' => 2], ['name' => 'c', 'quantity' => 3]]);
        $selector = json_encode(['$or' => [['name' => 'a'], ['quantity' => ['$gte' => 3]]]]);
        $this->assertSame(['a', 'c'], $this->names(['selector' => $selector, 'sort' => ['name']]));
        $this->assertSame(['b'], $this->names(['selector' => '{"name": {"$in": ["b", "z"]}}']));
    }

    public function testOneFailingRowRollsBackTheWholeUpsert(): void
    {
        $this->type->failOnName = 'b';
        try {
            $this->upsert([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
            $this->fail('expected the second row to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('beforeWrite failed on b', $e->getMessage());
        }
        $this->assertSame(0, $this->rowCount(), 'row a was written before b failed; it must be rolled back');
        $this->assertFalse(TestEnvironment::pdo()->inTransaction());
    }

    public function testInsideAnOuterTransactionASavepointIsUsed(): void
    {
        $pdo = TestEnvironment::pdo();
        $pdo->beginTransaction();
        $this->upsert([['name' => 'outer']]);

        $this->type->failOnName = 'b';
        try {
            $this->upsert([['name' => 'a'], ['name' => 'b']]);
            $this->fail('expected the second row to fail');
        } catch (\RuntimeException $e) {
            // expected
        }
        $this->assertTrue($pdo->inTransaction(), 'the outer transaction must survive an inner failure');
        $this->assertSame(1, $this->rowCount(), "only the failed call's rows are undone");

        $pdo->rollBack();
        $this->assertSame(0, $this->rowCount(), 'a successful inner call must not have committed');
    }

    public function testDeleteReturnsTheRowsItRemoved(): void
    {
        $rows = $this->upsert([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
        $deleted = $this->type->resolveDelete(null, ['id' => [$rows[0]['id'], $rows[2]['id']]], $this->context);
        $this->assertSame(['a', 'c'], array_column($deleted, 'name'));
        $this->assertSame(['b'], $this->names(['sort' => ['name']]));
    }

    public function testDeleteIsAllOrNothing(): void
    {
        $rows = $this->upsert([['name' => 'a']]);
        try {
            $this->type->resolveDelete(null, ['id' => [$rows[0]['id'], 999999]], $this->context);
            $this->fail('expected the unknown id to fail');
        } catch (UserError $e) {
            $this->assertStringContainsString("'999999' not found", $e->getMessage());
        }
        $this->assertSame(1, $this->rowCount());
    }

    public function testHooksSeeEveryVerb(): void
    {
        $id = (int) $this->upsert([['name' => 'a']])[0]['id'];
        $this->upsert([['id' => $id, 'name' => 'a2']]);
        $this->type->resolveList(null, [], $this->context);
        $this->type->resolveDelete(null, ['id' => [$id]], $this->context);

        $this->assertSame(
            [['create', null], ['edit', $id], ['list', null], ['delete', $id]],
            $this->type->authorized
        );
        $this->assertSame([['a', false], ['a2', true]], $this->type->written);
    }

    public function testARefusalWritesNothing(): void
    {
        $this->type->refuse = 'create';
        try {
            $this->upsert([['name' => 'a']]);
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertSame('refused create', $e->getMessage());
        }
        $this->assertSame(0, $this->rowCount());
    }
}
