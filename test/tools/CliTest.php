<?php

namespace Anorm\GraphQL\Test\Tools;

use Anorm\GraphQL\Test\Fixtures\Type\ProjectModelType;
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
        $this->assertSame([0, '0.3.0'], $this->cli(['--version']));
        [$exit, $output] = $this->cli(['--help']);
        $this->assertSame(0, $exit);
        foreach (
            [
                '--models', '--type-ns', '--schema', '--readonly', '--dry-run', '--force', '--mutations',
                '--input-only', '--without-update', '--without-delete',
            ] as $option
        ) {
            $this->assertStringContainsString($option, $output);
        }
    }

    public function testTypeBaseEndToEnd(): void
    {
        $arguments = $this->make();
        $arguments[array_search('-t', $arguments, true) + 1] = 'CliBase\Type';
        $arguments[] = '--type-base';
        $arguments[] = ProjectModelType::class;
        [$exit, $output] = $this->cli($arguments);
        $this->assertSame(0, $exit, $output);

        // The generated classes load against the real base and are built on it.
        require_once "$this->dir/Type/Widget/Base/WidgetTypeBase.php";
        require_once "$this->dir/Type/Widget/WidgetType.php";
        $type = new \CliBase\Type\Widget\WidgetType();
        $this->assertInstanceOf(ProjectModelType::class, $type);
        $this->assertSame('project', $type->projectBase());
        $this->assertSame('WidgetType', $type->name);
    }

    public function testABadTypeBaseExitsTwoAndWritesNothing(): void
    {
        [$exit, $output] = $this->cli(array_merge($this->make(), ['--type-base', 'Nope\Missing']));
        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString("Error: --type-base 'Nope\Missing' cannot be loaded", $output);
        $this->assertSame(['.', '..'], scandir($this->dir), 'nothing may be written');
    }

    public function testHelpNamesTheTypeBaseOption(): void
    {
        [$exit, $output] = $this->cli(['--help']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('--type-base', $output);
        $this->assertStringContainsString('[default: Anorm\GraphQL\ModelType]', $output);
    }

    public function testCreateUpdateEndToEnd(): void
    {
        $arguments = $this->make();
        $arguments[array_search('-t', $arguments, true) + 1] = 'CliCu\Type';
        $arguments[] = '--mutations';
        $arguments[] = 'create-update';
        [$exit, $output] = $this->cli($arguments);
        $this->assertSame(0, $exit, $output);

        foreach (['Create', 'Update'] as $kind) {
            require_once "$this->dir/Type/Widget/Base/Widget{$kind}InputBase.php";
            require_once "$this->dir/Type/Widget/Widget{$kind}Input.php";
        }
        $create = new \CliCu\Type\Widget\WidgetCreateInput();
        $update = new \CliCu\Type\Widget\WidgetUpdateInput();
        $this->assertSame('WidgetCreateInput', $create->name);
        $this->assertArrayNotHasKey('id', $create->getFields());
        $this->assertSame('ID!', (string) $update->getField('id')->getType());
    }

    public function testABadMutationsValueExitsTwoAndWritesNothing(): void
    {
        [$exit, $output] = $this->cli(array_merge($this->make(), ['--mutations', 'create']));
        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString("Error: --mutations 'create' must be 'upsert' or 'create-update'", $output);
        $this->assertSame(['.', '..'], scandir($this->dir), 'nothing may be written');
    }

    public function testHelpNamesTheMutationsOption(): void
    {
        [$exit, $output] = $this->cli(['--help']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('--mutations', $output);
        $this->assertStringContainsString('[default: upsert]', $output);
    }

    public function testInputOnlyThroughTheCommandLine(): void
    {
        [$exit, $output] = $this->cli(array_merge($this->make(), ['--input-only', 'Owner']));
        $this->assertSame(0, $exit, $output);
        $this->assertFileExists("$this->dir/Type/Owner/OwnerInput.php");
        $this->assertFileDoesNotExist("$this->dir/Type/Owner/OwnerType.php");
    }

    public function testWithoutUpdateThroughTheCommandLine(): void
    {
        $arguments = array_merge($this->make(), ['--mutations', 'create-update', '--without-update', 'Widget']);
        [$exit, $output] = $this->cli($arguments);
        $this->assertSame(0, $exit, $output);
        $this->assertFileExists("$this->dir/Type/Widget/WidgetCreateInput.php");
        $this->assertFileDoesNotExist("$this->dir/Type/Widget/WidgetUpdateInput.php");
        $schema = file_get_contents("$this->dir/ApiSchema.php");
        $this->assertStringContainsString("'widgetCreate'", $schema);
        $this->assertStringNotContainsString("'widgetUpdate'", $schema);
    }

    public function testWithoutUpdateNeedsCreateUpdateThroughTheCommandLine(): void
    {
        [$exit, $output] = $this->cli(array_merge($this->make(), ['--without-update', 'Widget']));
        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString(
            "--without-update names 'Widget', which needs --mutations create-update, not 'upsert'",
            $output
        );
        $this->assertSame(['.', '..'], scandir($this->dir), 'nothing may be written');
    }

    public function testWithoutDeleteThroughTheCommandLine(): void
    {
        [$exit, $output] = $this->cli(array_merge($this->make(), ['--without-delete', 'Owner']));
        $this->assertSame(0, $exit, $output);
        $schema = file_get_contents("$this->dir/ApiSchema.php");
        $this->assertStringContainsString("'ownerUpsert'", $schema);
        $this->assertStringNotContainsString("'ownerDelete'", $schema);
        $this->assertStringContainsString("'widgetDelete'", $schema);
    }
}
