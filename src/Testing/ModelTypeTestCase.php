<?php

namespace Anorm\GraphQL\Testing;

use Anorm\GraphQL\ModelType;
use DI\Container;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The tests every model-backed Type gets. A generated test supplies configuration;
 * the project's own TestCase supplies the container and the schema.
 *
 * Nothing is truncated. Each test runs inside a transaction that tearDown rolls
 * back. That only cleans up where the table's storage engine has transactions: on a
 * MyISAM table a rollback does nothing, and the rows a test wrote would stay for good.
 * So before it writes, the lifecycle test checks the engine, and skips itself, saying
 * why, rather than leave rows behind in somebody's database.
 */
abstract class ModelTypeTestCase extends TestCase
{
    /** @var Container */
    protected $container;

    /** @var \PDO|null Set once a test has asked for the database */
    private $pdo = null;

    abstract protected function createContainer(): Container;

    abstract protected function createSchema(Container $container): Schema;

    /** @return string Class name of the Type under test */
    abstract protected function typeClass(): string;

    /** @return string|null Class name of its Input, or null when read-only */
    abstract protected function inputClass(): ?string;

    /** @return string The schema field prefix, e.g. 'client' for clientList */
    abstract protected function entityName(): string;

    /** @return array<string, string> Field name => GraphQL type as printed, e.g. 'ID!' */
    abstract protected function expectedFieldTypes(): array;

    /** @return array<string, mixed> One valid input, without the key */
    protected function sampleInput(): array
    {
        return [];
    }

    /** @return array<string, mixed> Fields to change in the update step */
    protected function sampleUpdate(): array
    {
        return [];
    }

    protected function keyField(): string
    {
        return 'id';
    }

    /**
     * Return true to let the lifecycle test write to a table whose engine has no
     * transactions. Its rows will NOT be cleaned up. Only for a throwaway database.
     */
    protected function allowNonTransactionalTables(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createContainer();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo = null;
        parent::tearDown();
    }

    public function testTypeHasTheExpectedFields(): void
    {
        $type = $this->container->get($this->typeClass());
        $actual = [];
        foreach ($type->getFields() as $name => $field) {
            $actual[$name] = (string) $field->getType();
        }
        foreach ($this->expectedFieldTypes() as $name => $expected) {
            $this->assertArrayHasKey($name, $actual, "{$type->name} should have a field '$name'");
            $this->assertSame($expected, $actual[$name], "{$type->name}.$name");
        }
    }

    public function testInputMirrorsTheType(): void
    {
        if ($this->inputClass() === null) {
            $this->addToAssertionCount(1);
            return;
        }
        $input = $this->container->get($this->inputClass());
        $actual = [];
        foreach ($input->getFields() as $name => $field) {
            $actual[$name] = (string) $field->getType();
        }
        foreach ($this->expectedFieldTypes() as $name => $expected) {
            $this->assertArrayHasKey($name, $actual, "{$input->name} should have a field '$name'");
            // Nothing is required on an input: a missing key means create.
            $this->assertSame(rtrim($expected, '!'), $actual[$name], "{$input->name}.$name");
        }
    }

    public function testListReturnsAList(): void
    {
        $this->useDatabase();
        $this->assertIsArray($this->listAll());
    }

    public function testLifecycle(): void
    {
        if ($this->inputClass() === null) {
            $this->addToAssertionCount(1);
            return;
        }
        if (!$this->sampleInput()) {
            $this->markTestSkipped('sampleInput() is empty');
        }
        $this->useDatabase();
        $this->requireTransactionalTable();
        $key = $this->keyField();
        $prefix = $this->entityName();
        $before = count($this->listAll());

        $created = $this->upsert([$this->sampleInput(), $this->sampleInput()]);
        $this->assertCount(2, $created, 'upsert should return both created rows');
        $this->assertNotNull($created[0][$key], 'a created row should come back with its key');
        $this->assertNotSame('', $created[0][$key], 'a created row should come back with its key');
        foreach ($this->sampleInput() as $name => $value) {
            $this->assertEquals($value, $created[0][$name], "created $name");
        }
        $this->assertCount($before + 2, $this->listAll(), 'list should grow by two');

        $id = $created[0][$key];
        $one = $this->listWhere([$key => $id]);
        $this->assertCount(1, $one, 'a selector on the key is the single-item view');
        $this->assertEquals($id, $one[0][$key]);

        if ($this->sampleUpdate()) {
            $updated = $this->upsert([[$key => $id] + $this->sampleUpdate()]);
            $this->assertCount(1, $updated);
            $this->assertEquals($id, $updated[0][$key], 'an upsert with a key updates in place');
            foreach ($this->sampleUpdate() as $name => $value) {
                $this->assertEquals($value, $updated[0][$name], "updated $name");
            }
            $this->assertCount($before + 2, $this->listAll(), 'an update should not add a row');
        }

        $deleted = $this->execute(
            "mutation (\$id: [ID!]!) { {$prefix}Delete(id: \$id) { {$this->selection()} } }",
            ['id' => [$id]]
        )["{$prefix}Delete"];
        $this->assertCount(1, $deleted, 'delete should return the row it removed');
        $this->assertEquals($id, $deleted[0][$key]);
        $this->assertCount(0, $this->listWhere([$key => $id]), 'the deleted row should be gone');
        $this->assertCount($before + 1, $this->listAll());

        if (!$this->sampleUpdate()) {
            // Create, view and delete were exercised; saying nothing would pass the update off as tested.
            $this->markTestIncomplete('sampleUpdate() is empty, so updating was not exercised');
        }
    }

    /**
     * Run a query and return its data, failing the test on any GraphQL error.
     * A mutation begins the clean-up transaction first, and is refused (the test is
     * skipped) on a table that cannot roll back; see requireTransactionalTable().
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    protected function execute(string $query, array $variables = []): array
    {
        if (preg_match('/^\s*mutation\b/i', $query) === 1) {
            // Whoever runs a mutation, the inherited lifecycle test or a test the project
            // wrote itself, it happens inside the transaction tearDown rolls back, and
            // only on a table that can roll back.
            $this->useDatabase();
            $this->requireTransactionalTable();
        }
        $result = GraphQL::executeQuery(
            $this->createSchema($this->container),
            $query,
            null,
            $this->container,
            $variables
        )->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);
        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));
        return $result['data'];
    }

    /**
     * Skip, before anything is written, when the Type's table cannot roll back.
     *
     * MySQL and MariaDB say per engine whether it has transactions. Any other database,
     * or a Type that is not a ModelType, is left to the project's own judgement.
     */
    private function requireTransactionalTable(): void
    {
        $type = $this->container->get($this->typeClass());
        if (!$type instanceof ModelType || $this->pdo === null || $this->allowNonTransactionalTables()) {
            return;
        }
        $table = $type->tableName($this->container);
        try {
            $statement = $this->pdo->prepare(
                'SELECT t.ENGINE, e.TRANSACTIONS FROM information_schema.TABLES t'
                . ' LEFT JOIN information_schema.ENGINES e ON e.ENGINE = t.ENGINE'
                . ' WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ?'
            );
            $statement->execute([$table]);
            $row = $statement->fetch(\PDO::FETCH_NUM);
        } catch (\PDOException $e) {
            return;
        }
        if (is_array($row) && strtoupper((string) $row[1]) === 'NO') {
            $this->markTestSkipped(
                "Table '$table' uses the {$row[0]} engine, which has no transactions: this test's rows could not be "
                . 'rolled back and would stay in the database. Convert the table to InnoDB in the test database, or '
                . 'override allowNonTransactionalTables() if leaving rows behind is acceptable there.'
            );
        }
    }

    /** Begin the transaction that tearDown rolls back. */
    protected function useDatabase(): void
    {
        if ($this->pdo === null) {
            $this->pdo = $this->container->get(\PDO::class);
            $this->pdo->beginTransaction();
        }
    }

    protected function selection(): string
    {
        return implode(' ', array_keys($this->expectedFieldTypes()));
    }

    /** @return array<int, array<string, mixed>> */
    protected function listAll(): array
    {
        $prefix = $this->entityName();
        return $this->execute("{ {$prefix}List { {$this->selection()} } }")["{$prefix}List"];
    }

    /**
     * @param array<string, mixed> $selector
     * @return array<int, array<string, mixed>>
     */
    protected function listWhere(array $selector): array
    {
        $prefix = $this->entityName();
        return $this->execute(
            "query (\$q: MangoInput) { {$prefix}List(query: \$q) { {$this->selection()} } }",
            ['q' => ['selector' => json_encode($selector)]]
        )["{$prefix}List"];
    }

    /**
     * @param array<int, array<string, mixed>> $inputs
     * @return array<int, array<string, mixed>>
     */
    protected function upsert(array $inputs): array
    {
        $prefix = $this->entityName();
        // The Input's own GraphQL name, rather than a guess from the prefix.
        $inputType = $this->container->get((string) $this->inputClass())->name;
        return $this->execute(
            "mutation (\$input: [{$inputType}!]!) { {$prefix}Upsert(input: \$input) { {$this->selection()} } }",
            ['input' => $inputs]
        )["{$prefix}Upsert"];
    }
}
