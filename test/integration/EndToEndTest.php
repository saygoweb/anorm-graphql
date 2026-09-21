<?php

namespace Anorm\GraphQL\Test\Integration;

use Anorm\GraphQL\Test\TempDir;
use Anorm\GraphQL\Test\TestEnvironment;
use Anorm\GraphQL\Tools\TypeMaker;
use Anorm\GraphQL\Tools\TypeMakerOptions;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use PHPUnit\Framework\TestCase;

/**
 * Generate, load what was generated, and run real GraphQL through it. Golden files
 * show the generator writes what is expected; only this shows that it works.
 */
class EndToEndTest extends TestCase
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
        $o->modelsDir = dirname(__DIR__) . '/Fixtures/Model';
        $o->modelNamespace = 'Anorm\GraphQL\Test\Fixtures\Model';
        $o->outputDir = "$this->dir/src/Type";
        $o->typeNamespace = 'E2E\Type';
        $o->testsDir = "$this->dir/tests";
        $o->testNamespace = 'E2E\Tests';
        $o->schemaPath = "$this->dir/src/ApiSchema.php";
        $o->schemaNamespace = 'E2E';
        $o->readOnly = ['Owner'];
        $maker = new TypeMaker($o);
        $this->assertSame(0, $maker->run(), implode("\n", $maker->report));

        file_put_contents("$this->dir/bootstrap.php", <<<'PHP'
<?php
$loader = require getenv('E2E_AUTOLOAD');
$loader->addPsr4('E2E\\Tests\\', __DIR__ . '/tests/');
$loader->addPsr4('E2E\\', __DIR__ . '/src/');
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function autoload(): string
    {
        return dirname(__DIR__, 2) . '/vendor/autoload.php';
    }

    /**
     * Run PHP in a child process: the generated classes are declared there, so each
     * test can generate afresh without "class already declared".
     */
    private function runPhp(string $command): array
    {
        $env = 'E2E_AUTOLOAD=' . escapeshellarg($this->autoload());
        exec("$env $command 2>&1", $output, $exit);
        return [$exit, implode("\n", $output)];
    }

    public function testTheGeneratedTestsPassAgainstTheGeneratedTypes(): void
    {
        $phpunit = dirname(__DIR__, 2) . '/vendor/bin/phpunit';
        [$exit, $output] = $this->runPhp(
            'php ' . escapeshellarg($phpunit) . ' --no-configuration --bootstrap '
            . escapeshellarg("$this->dir/bootstrap.php") . ' ' . escapeshellarg("$this->dir/tests")
        );
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('OK (', $output);
        $this->assertStringNotContainsString('Skipped', $output, 'nothing generated should need skipping');
    }

    public function testAnEmptySampleUpdateIsReportedIncompleteNotPassedInSilence(): void
    {
        $test = "$this->dir/tests/WidgetTypeTest.php";
        $emptied = preg_replace("/('name' => 'name 2',\n)/", '', file_get_contents($test), 1, $count);
        $this->assertSame(1, $count);
        file_put_contents($test, $emptied);

        $phpunit = dirname(__DIR__, 2) . '/vendor/bin/phpunit';
        [$exit, $output] = $this->runPhp(
            'php ' . escapeshellarg($phpunit) . ' --no-configuration --verbose --bootstrap '
            . escapeshellarg("$this->dir/bootstrap.php") . ' ' . escapeshellarg($test)
        );
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Incomplete: 1', $output);
        $this->assertStringContainsString('sampleUpdate() is empty, so updating was not exercised', $output);
    }

    public function testAQueryThroughTheGeneratedSchema(): void
    {
        file_put_contents("$this->dir/query.php", <<<'PHP'
<?php
require __DIR__ . '/bootstrap.php';
$container = Anorm\GraphQL\Test\TestEnvironment::container();
$schema = new E2E\ApiSchema($container);
$schema->assertValid();
$run = function ($query, $variables = []) use ($schema, $container) {
    return GraphQL\GraphQL::executeQuery($schema, $query, null, $container, $variables)
        ->toArray(GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE);
};
$pdo = $container->get(PDO::class);
$pdo->beginTransaction();
$out = [];
$out['upsert'] = $run(
    'mutation ($input: [WidgetInput!]!) { widgetUpsert(input: $input) { id name quantity price active notes } }',
    ['input' => [['name' => 'e2e', 'quantity' => 3, 'price' => 1.5, 'active' => true]]]
);
$id = $out['upsert']['data']['widgetUpsert'][0]['id'];
$out['view'] = $run(
    'query ($q: MangoInput) { widgetList(query: $q) { id name } }',
    ['q' => ['selector' => json_encode(['id' => $id])]]
);
$out['owners'] = $run('{ ownerList { id name } }');
$out['mutations'] = array_keys($schema->getMutationType()->getFields());
$out['badSelector'] = $run('{ widgetList(query: {selector: "{nope"}) { id } }');
$pdo->rollBack();
echo json_encode($out);
PHP
        );
        [$exit, $output] = $this->runPhp('php ' . escapeshellarg("$this->dir/query.php"));
        $this->assertSame(0, $exit, $output);
        $out = json_decode($output, true);
        $this->assertIsArray($out, $output);

        $this->assertArrayNotHasKey('errors', $out['upsert'], $output);
        $widget = $out['upsert']['data']['widgetUpsert'][0];
        $this->assertSame('e2e', $widget['name']);
        $this->assertSame(3, $widget['quantity']);
        $this->assertSame(1.5, $widget['price']);
        $this->assertTrue($widget['active']);
        $this->assertNull($widget['notes']);

        $this->assertCount(1, $out['view']['data']['widgetList'], 'a selector on the key is the single-item view');
        $this->assertSame([], $out['owners']['data']['ownerList']);
        $this->assertSame(['widgetDelete', 'widgetUpsert'], $out['mutations'], 'Owner is read-only: no mutations');
        $this->assertStringContainsString('query.selector', $out['badSelector']['errors'][0]['message'], 'a UserError reaches the client');
    }
}
