<?php

namespace Anorm\GraphQL\Test\Integration;

use Anorm\GraphQL\Test\Fixtures\LegacyWidgetCase;
use Anorm\GraphQL\Test\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The test case consumers extend runs against their real database, and cleans up by
 * rolling back. A table that cannot roll back must not be written to.
 */
class ModelTypeTestCaseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnvironment::createTables();
    }

    protected function setUp(): void
    {
        TestEnvironment::pdo()->exec('DELETE FROM `legacy_widgets`');
        LegacyWidgetCase::$allow = false;
    }

    protected function tearDown(): void
    {
        LegacyWidgetCase::$allow = false;
        TestEnvironment::pdo()->exec('DELETE FROM `legacy_widgets`');
    }

    private function rows(): int
    {
        return (int) TestEnvironment::pdo()->query('SELECT COUNT(*) FROM `legacy_widgets`')->fetchColumn();
    }

    public function testTheLifecycleTestRefusesToWriteToATableThatCannotRollBack(): void
    {
        $result = (new LegacyWidgetCase('testLifecycle'))->run();

        $this->assertSame(1, $result->skippedCount(), 'the test must skip itself rather than leave rows behind');
        $this->assertSame(0, $result->errorCount() + $result->failureCount());
        $message = $result->skipped()[0]->thrownException()->getMessage();
        $this->assertStringContainsString("Table 'legacy_widgets' uses the MyISAM engine", $message);
        $this->assertStringContainsString('allowNonTransactionalTables()', $message);
        $this->assertSame(0, $this->rows(), 'nothing may have been written');
    }

    public function testAProjectCanAcceptTheConsequences(): void
    {
        LegacyWidgetCase::$allow = true;
        $result = (new LegacyWidgetCase('testLifecycle'))->run();

        $this->assertTrue($result->wasSuccessful(), 'with the guard lifted the lifecycle itself must pass');
        $this->assertSame(0, $result->skippedCount());
        $this->assertSame(1, $this->rows(), 'and this is why the guard exists: the rollback did nothing');
    }

    public function testReadingNeedsNoGuard(): void
    {
        $result = (new LegacyWidgetCase('testListReturnsAList'))->run();
        $this->assertTrue($result->wasSuccessful());
        $this->assertSame(0, $result->skippedCount());
    }
}
