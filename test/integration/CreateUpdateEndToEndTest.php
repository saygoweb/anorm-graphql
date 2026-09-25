<?php

namespace Anorm\GraphQL\Test\Integration;

use Anorm\GraphQL\Test\TempDir;
use Anorm\GraphQL\Test\TestEnvironment;
use Anorm\GraphQL\Tools\TypeMaker;
use Anorm\GraphQL\Tools\TypeMakerOptions;
use PHPUnit\Framework\TestCase;

/** `--mutations create-update`, generated and then used for real. */
class CreateUpdateEndToEndTest extends TestCase
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
        $o->typeNamespace = 'Cu\Type';
        $o->testsDir = "$this->dir/tests";
        $o->testNamespace = 'Cu\Tests';
        $o->schemaPath = "$this->dir/src/ApiSchema.php";
        $o->schemaNamespace = 'Cu';
        $o->mutations = 'create-update';
        $maker = new TypeMaker($o);
        $this->assertSame(0, $maker->run(), implode("\n", $maker->report));

        file_put_contents("$this->dir/bootstrap.php", <<<'PHP'
<?php
$loader = require getenv('CU_AUTOLOAD');
$loader->addPsr4('Cu\\Tests\\', __DIR__ . '/tests/');
$loader->addPsr4('Cu\\', __DIR__ . '/src/');
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
        $env = 'CU_AUTOLOAD=' . escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php');
        exec("$env $command 2>&1", $output, $exit);
        return [$exit, implode("\n", $output)];
    }

    public function testTheGeneratedTestsPassThroughCreateAndUpdate(): void
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

    public function testCreateAndUpdateThroughTheGeneratedSchema(): void
    {
        file_put_contents("$this->dir/query.php", <<<'PHP'
<?php
require __DIR__ . '/bootstrap.php';
$container = Anorm\GraphQL\Test\TestEnvironment::container();
$schema = new Cu\ApiSchema($container);
$schema->assertValid();
$run = function ($query, $variables = []) use ($schema, $container) {
    return GraphQL\GraphQL::executeQuery($schema, $query, null, $container, $variables)
        ->toArray(GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);
};
$pdo = $container->get(PDO::class);
$pdo->beginTransaction();
$out = [];
$out['create'] = $run(
    'mutation ($input: [EventCreateInput!]!) { eventCreate(input: $input) { id title notes } }',
    ['input' => [['title' => 'launch', 'notes' => 'n']]]
);
$id = $out['create']['data']['eventCreate'][0]['id'];
$out['update'] = $run(
    'mutation ($input: [EventUpdateInput!]!) { eventUpdate(input: $input) { id title notes } }',
    ['input' => [['id' => $id, 'title' => 'launch 2']]]
);
$out['missingTitle'] = $run(
    'mutation ($input: [EventCreateInput!]!) { eventCreate(input: $input) { id } }',
    ['input' => [['notes' => 'no title']]]
);
$out['updateWithoutId'] = $run(
    'mutation ($input: [EventUpdateInput!]!) { eventUpdate(input: $input) { id } }',
    ['input' => [['title' => 'x']]]
);
$out['mutations'] = array_keys($schema->getMutationType()->getFields());
$pdo->rollBack();
echo json_encode($out);
PHP
        );
        [$exit, $output] = $this->runPhp('php ' . escapeshellarg("$this->dir/query.php"));
        $this->assertSame(0, $exit, $output);
        $out = json_decode($output, true);
        $this->assertIsArray($out, $output);

        $this->assertArrayNotHasKey('errors', $out['create'], $output);
        $this->assertArrayNotHasKey('errors', $out['update'], $output);
        $this->assertSame('launch 2', $out['update']['data']['eventUpdate'][0]['title']);
        $this->assertSame('n', $out['update']['data']['eventUpdate'][0]['notes'], 'update changes only what it names');
        $this->assertArrayHasKey('errors', $out['missingTitle'], 'title is required on create');
        $this->assertArrayHasKey('errors', $out['updateWithoutId'], 'an update names its row');
        sort($out['mutations']);
        $this->assertSame(['eventCreate', 'eventDelete', 'eventUpdate'], $out['mutations']);
    }
}
