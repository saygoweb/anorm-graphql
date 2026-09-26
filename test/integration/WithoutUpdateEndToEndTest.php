<?php

namespace Anorm\GraphQL\Test\Integration;

use Anorm\GraphQL\Test\TempDir;
use Anorm\GraphQL\Test\TestEnvironment;
use Anorm\GraphQL\Tools\TypeMaker;
use Anorm\GraphQL\Tools\TypeMakerOptions;
use PHPUnit\Framework\TestCase;

/** `--mutations create-update --without-update Event`, generated and then used for real. */
class WithoutUpdateEndToEndTest extends TestCase
{
    use TempDir;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::createTables();
    }

    protected function setUp(): void
    {
        $this->makeTempDir();
        $o = new TypeMakerOptions();
        $o->modelsDir = dirname(__DIR__) . '/Fixtures/CalendarModel';
        $o->modelNamespace = 'Anorm\GraphQL\Test\Fixtures\CalendarModel';
        $o->outputDir = "$this->dir/src/Type";
        $o->typeNamespace = 'Wu\Type';
        $o->testsDir = "$this->dir/tests";
        $o->testNamespace = 'Wu\Tests';
        $o->schemaPath = "$this->dir/src/ApiSchema.php";
        $o->schemaNamespace = 'Wu';
        $o->mutations = 'create-update';
        $o->withoutUpdate = ['Event'];
        $maker = new TypeMaker($o);
        $this->assertSame(0, $maker->run(), implode("\n", $maker->report));

        file_put_contents("$this->dir/bootstrap.php", <<<'PHP'
<?php
$loader = require getenv('WU_AUTOLOAD');
$loader->addPsr4('Wu\\Tests\\', __DIR__ . '/tests/');
$loader->addPsr4('Wu\\', __DIR__ . '/src/');
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /** @return array{0: int, 1: string} */
    private function runPhp(string $command): array
    {
        $env = 'WU_AUTOLOAD=' . escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php');
        exec("$env $command 2>&1", $output, $exit);
        return [$exit, implode("\n", $output)];
    }

    public function testTheGeneratedInputAndTestFilesHaveNoUpdate(): void
    {
        $this->assertFileExists("$this->dir/src/Type/Event/EventCreateInput.php");
        $this->assertFileDoesNotExist("$this->dir/src/Type/Event/EventUpdateInput.php");
        $this->assertFileDoesNotExist("$this->dir/src/Type/Event/Base/EventUpdateInputBase.php");
        $this->assertStringNotContainsString('EventUpdateInput', file_get_contents("$this->dir/tests/EventTypeTest.php"));
    }

    public function testTheGeneratedTestsPassWithNoUpdateStepAndNoIncompleteNoise(): void
    {
        $phpunit = dirname(__DIR__, 2) . '/vendor/bin/phpunit';
        [$exit, $output] = $this->runPhp(
            'php ' . escapeshellarg($phpunit) . ' --no-configuration --verbose --bootstrap '
            . escapeshellarg("$this->dir/bootstrap.php") . ' ' . escapeshellarg("$this->dir/tests")
        );
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('OK (', $output);
        $this->assertStringNotContainsString('Skipped', $output);
        $this->assertStringNotContainsString('Incomplete', $output);
    }

    public function testCreateAndDeleteThroughTheGeneratedSchemaButNoUpdate(): void
    {
        file_put_contents("$this->dir/query.php", <<<'PHP'
<?php
require __DIR__ . '/bootstrap.php';
$container = Anorm\GraphQL\Test\TestEnvironment::container();
$schema = new Wu\ApiSchema($container);
$schema->assertValid();
$run = function ($query, $variables = []) use ($schema, $container) {
    return GraphQL\GraphQL::executeQuery($schema, $query, null, $container, $variables)
        ->toArray(GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);
};
$pdo = $container->get(PDO::class);
$pdo->beginTransaction();
$out = [];
$out['create'] = $run(
    'mutation ($input: [EventCreateInput!]!) { eventCreate(input: $input) { id title } }',
    ['input' => [['title' => 'launch']]]
);
$id = $out['create']['data']['eventCreate'][0]['id'];
$out['mutations'] = array_keys($schema->getMutationType()->getFields());
$out['update'] = $run(
    'mutation { eventUpdate(input: []) { id } }'
);
$out['delete'] = $run('mutation ($id: [ID!]!) { eventDelete(id: $id) { id } }', ['id' => [$id]]);
$pdo->rollBack();
echo json_encode($out);
PHP
        );
        [$exit, $output] = $this->runPhp('php ' . escapeshellarg("$this->dir/query.php"));
        $this->assertSame(0, $exit, $output);
        $out = json_decode($output, true);
        $this->assertIsArray($out, $output);

        $this->assertArrayNotHasKey('errors', $out['create'], $output);
        sort($out['mutations']);
        $this->assertSame(['eventCreate', 'eventDelete'], $out['mutations'], 'no eventUpdate at all');
        $this->assertArrayHasKey('errors', $out['update'], 'eventUpdate does not exist in the schema');
        $this->assertArrayNotHasKey('errors', $out['delete'], $output);
        $id = $out['create']['data']['eventCreate'][0]['id'];
        $this->assertSame($id, $out['delete']['data']['eventDelete'][0]['id']);
    }
}
