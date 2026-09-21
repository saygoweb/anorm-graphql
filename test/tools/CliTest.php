<?php

namespace Anorm\GraphQL\Test\Tools;

use Anorm\GraphQL\Test\TempDir;
use PHPUnit\Framework\TestCase;

/** The command line itself, run as a process: what it prints and how it exits. */
class CliTest extends TestCase
{
    use TempDir;

    protected function setUp(): void
    {
        $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /**
     * @param string[] $arguments
     * @return array{0: int, 1: string}
     */
    private function cli(array $arguments): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/anorm-graphql.php');
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        // Run from the scratch directory: if a default path were ever used by mistake, it lands there.
        exec('cd ' . escapeshellarg($this->dir) . ' && ' . $command . ' 2>&1', $output, $exit);
        return [$exit, implode("\n", $output)];
    }

    /** @return string[] */
    private function make(): array
    {
        return [
            'make',
            '--models=' . dirname(__DIR__) . '/Fixtures/Model',
            '-n', 'Anorm\GraphQL\Test\Fixtures\Model',
            '-o', "$this->dir/Type",
            '-t', 'Cli\Type',
            '--tests', 'none',
            '-s', "$this->dir/ApiSchema.php",
            '--schema-ns', 'Cli',
        ];
    }

    public function testMakeWritesAndExitsZero(): void
    {
        [$exit, $output] = $this->cli($this->make());
        $this->assertSame(0, $exit, $output);
        $this->assertFileExists("$this->dir/Type/Widget/Base/WidgetTypeBase.php");
        $this->assertStringContainsString('LedgerLineModel: no single key', $output);
    }

    public function testAMisspeltOptionIsAnErrorNotADefault(): void
    {
        $arguments = $this->make();
        $arguments[array_search('-o', $arguments, true)] = '--otuput';
        [$exit, $output] = $this->cli($arguments);
        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString("Unexpected argument '--otuput'", $output);
        $this->assertSame(['.', '..'], scandir($this->dir), 'nothing may be written, least of all to a default folder');
    }

    public function testAnUnknownCommandAndAnUnknownModelExitTwo(): void
    {
        [$exit, $output] = $this->cli(['bogus']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString("Unknown command 'bogus'", $output);

        [$exit, $output] = $this->cli(array_merge($this->make(), ['--only', 'Nope']));
        $this->assertSame(2, $exit);
        $this->assertStringContainsString("--only names 'Nope'", $output);
    }

    public function testVersionAndHelp(): void
    {
        $this->assertSame([0, '0.1.0'], $this->cli(['--version']));
        [$exit, $output] = $this->cli(['--help']);
        $this->assertSame(0, $exit);
        foreach (['--models', '--type-ns', '--schema', '--readonly', '--dry-run', '--force'] as $option) {
            $this->assertStringContainsString($option, $output);
        }
    }
}
