<?php

namespace Anorm\GraphQL\Test\Integration;

use Anorm\GraphQL\Test\TestEnvironment;
use PHPUnit\Framework\TestCase;

/** Proves the docker stack: the right PHP, the driver, and a database that answers. */
class StackTest extends TestCase
{
    public function testThePhpFloorAndTheDriver(): void
    {
        $this->assertTrue(version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION);
        $this->assertTrue(extension_loaded('pdo_mysql'), 'pdo_mysql is what Anorm connects with');
    }

    public function testTheDatabaseAnswers(): void
    {
        $this->assertSame('1', (string) TestEnvironment::pdo()->query('SELECT 1')->fetchColumn());
    }
}
