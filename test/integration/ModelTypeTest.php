<?php

namespace Anorm\GraphQL\Test\Integration;

use Anorm\GraphQL\Test\Fixtures\FailingReleasePdo;
use Anorm\GraphQL\Test\Fixtures\OtherModel\DynamicWidgetModel;
use Anorm\GraphQL\Test\Fixtures\Type\RecordingWidgetType;
use Anorm\GraphQL\Test\Fixtures\Type\ScopedDocumentType;
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
        TestEnvironment::pdo()->exec('DELETE FROM `documents`');
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

    /**
     * @param array<int, array<string, mixed>> $inputs
     * @return array<int, array<string, mixed>>
     */
    private function create(array $inputs): array
    {
        return $this->type->resolveCreate(null, ['input' => $inputs], $this->context);
    }

    /**
     * @param array<int, array<string, mixed>> $inputs
     * @return array<int, array<string, mixed>>
     */
    private function update(array $inputs): array
    {
        return $this->type->resolveUpdate(null, ['input' => $inputs], $this->context);
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
            'a key that looks like a number' => [['selector' => '{"2": 1}']],
            'a key that looks like a list position' => [['selector' => '{"0": 1}']],
            'a numeric key nested in $and' => [['selector' => '{"$and": [{"0": 1}]}']],
            'an empty key' => [['selector' => '{"": 1}']],
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

    /**
     * @dataProvider hostileIds
     */
    public function testAHostileIdIsBoundNeverConcatenated(string $id): void
    {
        $rows = $this->upsert([['name' => 'secret-a'], ['name' => 'secret-b']]);

        foreach (['delete', 'upsert'] as $mutation) {
            try {
                if ($mutation === 'delete') {
                    $this->type->resolveDelete(null, ['id' => [$id]], $this->context);
                } else {
                    $this->upsert([['id' => $id, 'name' => 'overwritten']]);
                }
                $this->fail("expected $mutation to find nothing for a hostile id");
            } catch (UserError $e) {
                $this->assertStringContainsString('not found', $e->getMessage());
            }
        }
        $this->assertSame(['secret-a', 'secret-b'], $this->names(['sort' => ['name']]), 'no row was touched');
        $this->assertSame(
            [['create', null], ['create', null], ['list', null]],
            $this->type->authorized,
            'authorize() must never be shown a row the client did not ask for'
        );
        $this->assertNotEmpty($rows);
    }

    /** @return array<string, array<int, string>> */
    public function hostileIds(): array
    {
        return [
            'tautology' => ["0' OR '1'='1"],
            'tautology, numeric' => ['0 OR 1=1'],
            'union' => ["0' UNION SELECT 1,2,3,4,5,6,7 -- "],
            'stacked' => ["1'; DELETE FROM widgets; -- "],
        ];
    }

    public function testAnIdThatOnlyStartsWithTheKeyIsNotThatRow(): void
    {
        $id = $this->upsert([['name' => 'a']])[0]['id'];
        foreach ([$id . "' -- ", $id . ' ', '0' . $id, $id . '.0'] as $almost) {
            try {
                $this->type->resolveDelete(null, ['id' => [$almost]], $this->context);
                $this->fail("expected '$almost' to find nothing");
            } catch (UserError $e) {
                $this->assertStringContainsString('not found', $e->getMessage());
            }
        }
        $this->assertSame(1, $this->rowCount());
        $this->assertCount(1, $this->type->resolveDelete(null, ['id' => [(int) $id]], $this->context), 'an integer id is fine');
    }

    /**
     * @dataProvider malformedSelectors
     */
    public function testAMalformedSelectorIsAClientSafeError(string $selector): void
    {
        $this->expectException(UserError::class);
        $this->names(['selector' => $selector]);
    }

    /** @return array<string, array<int, string>> */
    public function malformedSelectors(): array
    {
        return [
            '$and is not a list' => ['{"$and": "not-an-array"}'],
            'an unknown operator' => ['{"name": {"$nope": 1}}'],
            'a list, not an object' => ['[{"name": "a"}]'],
            'a bare string' => ['"name"'],
        ];
    }

    public function testAFieldNamedLikeAnOperatorIsRefusedInASelectorWithAReason(): void
    {
        $invoices = new ScopedDocumentType('InvoiceType', 10);
        try {
            $invoices->resolveList(null, ['query' => ['selector' => '{"type": 10}']], $this->context);
            $this->fail("expected 'type' to be refused");
        } catch (UserError $e) {
            $this->assertStringContainsString("reads that word as an operator", $e->getMessage());
        }
    }

    public function testAScopeSeparatesTwoTypesOverOneTable(): void
    {
        $invoices = new ScopedDocumentType('InvoiceType', 10);
        $quotes = new ScopedDocumentType('QuoteType', 32);

        $invoice = $invoices->resolveUpsert(null, ['input' => [['title' => 'inv']]], $this->context)[0];
        $quote = $quotes->resolveUpsert(null, ['input' => [['title' => 'quo']]], $this->context)[0];
        $this->assertEquals(10, $invoice['type'], 'the scope is stamped on a create');
        $this->assertEquals(32, $quote['type']);

        $this->assertSame(['inv'], array_column($invoices->resolveList(null, [], $this->context), 'title'));
        $this->assertSame(['quo'], array_column($quotes->resolveList(null, [], $this->context), 'title'));
        $query = ['selector' => '{"title": {"$in": ["inv", "quo"]}}'];
        $this->assertSame(
            ['quo'],
            array_column($quotes->resolveList(null, ['query' => $query], $this->context), 'title'),
            'the scope is ANDed with the selector, not replaced by it'
        );

        $outsideScope = [
            'resolveDelete' => ['id' => [$invoice['id']]],
            'resolveUpsert' => ['input' => [['id' => $invoice['id'], 'title' => 'x']]],
        ];
        foreach ($outsideScope as $method => $args) {
            try {
                $quotes->$method(null, $args, $this->context);
                $this->fail("expected $method to find nothing outside its scope");
            } catch (UserError $e) {
                $this->assertStringContainsString('not found', $e->getMessage());
            }
        }
        $this->assertSame(['inv'], array_column($invoices->resolveList(null, [], $this->context), 'title'));
    }

    public function testAnInputCannotMoveARowOutOfItsScope(): void
    {
        $invoices = new ScopedDocumentType('InvoiceType', 10);
        $id = $invoices->resolveUpsert(null, ['input' => [['title' => 'inv', 'type' => 10]]], $this->context)[0]['id'];
        try {
            $invoices->resolveUpsert(null, ['input' => [['id' => $id, 'type' => 32]]], $this->context);
            $this->fail('expected the scope property to be refused');
        } catch (UserError $e) {
            $this->assertStringContainsString("'type' is fixed for InvoiceType", $e->getMessage());
        }
        $this->assertCount(1, $invoices->resolveList(null, [], $this->context));
    }

    public function testAScopeNamingAnUnknownPropertyIsADeveloperError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("'type' is not a property");
        (new ScopedDocumentType('Broken', 1, \Anorm\GraphQL\Test\Fixtures\Model\WidgetModel::class))
            ->resolveList(null, [], $this->context);
    }

    public function testMutationsRefuseAModelInDynamicMode(): void
    {
        $type = new ScopedDocumentType('Dynamic', 1, DynamicWidgetModel::class);
        foreach (['resolveUpsert' => ['input' => [['name' => 'a']]], 'resolveDelete' => ['id' => [1]]] as $method => $args) {
            try {
                $type->$method(null, $args, $this->context);
                $this->fail("expected $method to refuse dynamic mode");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('static mode', $e->getMessage());
            }
        }
        $this->assertSame(0, $this->rowCount());
    }

    public function testAFailedRollbackDoesNotHideTheRealError(): void
    {
        TestEnvironment::pdo()->beginTransaction();
        $this->type->failOnName = 'b';
        $this->type->ddlBeforeFailing = true;
        try {
            $this->upsert([['name' => 'a'], ['name' => 'b']]);
            $this->fail('expected the second row to fail');
        } catch (\Throwable $e) {
            $this->assertSame('beforeWrite failed on b', $e->getMessage(), get_class($e));
        }
    }

    public function testAnImplicitCommitThatThenSucceedsIsReportedForWhatItIs(): void
    {
        TestEnvironment::pdo()->beginTransaction();
        $this->type->ddlInBeforeWrite = true;
        try {
            $this->upsert([['name' => 'a']]);
            $this->fail('expected the lost savepoint to be reported');
        } catch (\PDOException $e) {
            $this->fail('a bare driver error explains nothing: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('committed implicitly', $e->getMessage());
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious(), 'the driver error is kept as the cause');
        }
    }

    public function testAFailureToReleaseThatIsNotAnImplicitCommitIsLeftAsItIs(): void
    {
        $pdo = TestEnvironment::connect(FailingReleasePdo::class);
        $pdo->beginTransaction();
        try {
            $this->type->resolveUpsert(null, ['input' => [['name' => 'a']]], TestEnvironment::container($pdo));
            $this->fail('expected the release to fail');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('server has gone away', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->fail('a lost connection is not an implicit commit, and must not be called one: ' . $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
        $this->assertSame(0, $this->rowCount());
    }

    public function testAnImplicitCommitWithNoOuterTransactionNeverSurfacesAsADriverError(): void
    {
        // With no savepoint to miss, PHP 7.4's PDO cannot tell that the transaction it
        // began has gone, and commit() succeeds; PHP 8 notices and throws. Either way
        // the caller must not be handed a bare PDOException.
        $this->type->ddlInBeforeWrite = true;
        try {
            $rows = $this->upsert([['name' => 'a']]);
            $this->assertSame('a', $rows[0]['name']);
        } catch (\PDOException $e) {
            $this->fail('a bare driver error explains nothing: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('committed implicitly', $e->getMessage());
        }
        $this->assertSame(1, $this->rowCount(), 'DDL committed the row; nothing can take that back');
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

    public function testCreateMakesRowsAndReturnsThem(): void
    {
        $rows = $this->create([['name' => 'a', 'quantity' => 1], ['name' => 'b']]);
        $this->assertCount(2, $rows);
        $this->assertNotEmpty($rows[0]['id']);
        $this->assertSame(2, $this->rowCount());
        $this->assertSame([['create', null], ['create', null]], $this->type->authorized);
        $this->assertSame([['a', false], ['b', false]], $this->type->written);
    }

    public function testCreateWithAKeyIsAClientSafeErrorAndWritesNothing(): void
    {
        $id = $this->create([['name' => 'a']])[0]['id'];
        try {
            $this->create([['name' => 'b'], ['id' => $id, 'name' => 'c']]);
            $this->fail('expected a refusal');
        } catch (UserError $e) {
            $this->assertSame("WidgetType create does not take 'id'; to change a row, update it", $e->getMessage());
        }
        $this->assertSame(1, $this->rowCount(), 'all or nothing');
    }

    public function testAnEmptyOrNullKeyOnCreateIsNoKey(): void
    {
        $this->assertCount(2, $this->create([['id' => '', 'name' => 'a'], ['id' => null, 'name' => 'b']]));
    }

    public function testUpdateChangesOnlyWhatItNames(): void
    {
        $id = $this->create([['name' => 'a', 'quantity' => 7]])[0]['id'];
        $rows = $this->update([['id' => $id, 'name' => 'a2']]);
        $this->assertSame('a2', $rows[0]['name']);
        $this->assertEquals(7, $rows[0]['quantity'], 'a field the update does not name is left as it was');
        $this->assertSame(1, $this->rowCount());
        $this->assertSame([['create', null], ['edit', (int) $id]], $this->type->authorized);
        $this->assertSame([['a', false], ['a2', true]], $this->type->written);
    }

    public function testUpdateWithoutAKeyIsAClientSafeError(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage("WidgetType update needs 'id'");
        $this->update([['name' => 'x']]);
    }

    public function testUpdateOfAnUnknownKeyIsAClientSafeError(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage("WidgetType id '999999' not found");
        $this->update([['id' => '999999', 'name' => 'x']]);
    }

    public function testOneFailingRowRollsBackTheWholeUpdate(): void
    {
        $ids = array_column($this->create([['name' => 'a'], ['name' => 'b']]), 'id');
        $this->type->failOnName = 'b2';
        try {
            $this->update([['id' => $ids[0], 'name' => 'a2'], ['id' => $ids[1], 'name' => 'b2']]);
            $this->fail('expected a failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('beforeWrite failed on b2', $e->getMessage());
        }
        $this->assertSame(['a', 'b'], $this->names([]));
    }

    public function testCreateAndUpdateStayWithinTheScope(): void
    {
        $type = new ScopedDocumentType('InvoiceType', 10);
        $this->expectException(UserError::class);
        $this->expectExceptionMessage("'type' is fixed for InvoiceType");
        $type->resolveCreate(null, ['input' => [['type' => 99, 'title' => 'x']]], $this->context);
    }
}
