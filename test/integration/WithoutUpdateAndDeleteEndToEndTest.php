<?php

namespace Anorm\GraphQL\Test\Integration;

use Anorm\GraphQL\Test\TempDir;
use Anorm\GraphQL\Test\TestEnvironment;
use Anorm\GraphQL\Tools\TypeMaker;
use Anorm\GraphQL\Tools\TypeMakerOptions;
use PHPUnit\Framework\TestCase;

/**
 * `--mutations create-update --without-update Event --without-delete Event`, both
 * flags on the same entity, generated and then used for real. WithoutUpdateEndToEndTest
 * covers `--without-update` alone (with delete kept, as Release 3's Delivery/Invoice
 * use it); this covers the combination, whose two ModelTypeTestCase guards
 * ($canUpdate and hasDeleteMutation()) had otherwise never both evaluated false in
 * the same executed run (Checkpoint A review, finding I1).
 */
class WithoutUpdateAndDeleteEndToEndTest extends TestCase
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
        $o->typeNamespace = 'Wud\Type';
        $o->testsDir = "$this->dir/tests";
        $o->testNamespace = 'Wud\Tests';
        $o->schemaPath = "$this->dir/src/ApiSchema.php";
        $o->schemaNamespace = 'Wud';
        $o->mutations = 'create-update';
        $o->withoutUpdate = ['Event'];
        $o->withoutDelete = ['Event'];
        $maker = new TypeMaker($o);
        $this->assertSame(0, $maker->run(), implode("\n", $maker->report));

        file_put_contents("$this->dir/bootstrap.php", <<<'PHP'
<?php
$loader = require getenv('WUD_AUTOLOAD');
$loader->addPsr4('Wud\\Tests\\', __DIR__ . '/tests/');
$loader->addPsr4('Wud\\', __DIR__ . '/src/');
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
        $env = 'WUD_AUTOLOAD=' . escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php');
        exec("$env $command 2>&1", $output, $exit);
        return [$exit, implode("\n", $output)];
    }

    public function testTheGeneratedTestHasNeitherUpdateNorDeleteScaffolding(): void
    {
        $code = file_get_contents("$this->dir/tests/EventTypeTest.php");
        $this->assertStringNotContainsString('EventUpdateInput', $code);
        $this->assertStringContainsString('function usesCreateMutation(): bool', $code);
        $this->assertStringContainsString('function hasDeleteMutation(): bool', $code);
    }

    /**
     * The generated lifecycle test, actually run: with both flags on the same entity,
     * ModelTypeTestCase::testLifecycle()'s update guard ($canUpdate) and delete guard
     * (hasDeleteMutation()) must both take their "skip" branch in the same run, with
     * no Skipped/Incomplete noise — only create and list are exercised.
     */
    public function testTheGeneratedTestsPassExercisingOnlyCreateAndList(): void
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

    public function testTheGeneratedSchemaHasCreateOnlyNeitherUpdateNorDelete(): void
    {
        file_put_contents("$this->dir/query.php", <<<'PHP'
<?php
require __DIR__ . '/bootstrap.php';
$container = Anorm\GraphQL\Test\TestEnvironment::container();
$schema = new Wud\ApiSchema($container);
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
$out['mutations'] = array_keys($schema->getMutationType()->getFields());
$out['update'] = $run('mutation { eventUpdate(input: []) { id } }');
$out['delete'] = $run(
    'mutation ($id: [ID!]!) { eventDelete(id: $id) { id } }',
    ['id' => [$out['create']['data']['eventCreate'][0]['id']]]
);
$pdo->rollBack();
echo json_encode($out);
PHP
        );
        [$exit, $output] = $this->runPhp('php ' . escapeshellarg("$this->dir/query.php"));
        $this->assertSame(0, $exit, $output);
        $out = json_decode($output, true);
        $this->assertIsArray($out, $output);

        $this->assertArrayNotHasKey('errors', $out['create'], $output);
        $this->assertSame(['eventCreate'], $out['mutations'], 'no eventUpdate and no eventDelete at all');
        $this->assertArrayHasKey('errors', $out['update'], 'eventUpdate does not exist in the schema');
        $this->assertArrayHasKey('errors', $out['delete'], 'eventDelete does not exist in the schema');
    }
}
