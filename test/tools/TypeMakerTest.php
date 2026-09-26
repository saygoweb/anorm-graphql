<?php

namespace Anorm\GraphQL\Test\Tools;

use Anorm\GraphQL\Test\Fixtures\Type\FinalModelType;
use Anorm\GraphQL\Test\Fixtures\Type\ProjectModelType;
use Anorm\GraphQL\Tools\TypeMaker;
use Anorm\GraphQL\Tools\TypeMakerOptions;
use Anorm\GraphQL\Test\TempDir;
use PHPUnit\Framework\TestCase;

class TypeMakerTest extends TestCase
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

    private function options(): TypeMakerOptions
    {
        $o = new TypeMakerOptions();
        $o->modelsDir = dirname(__DIR__) . '/Fixtures/Model';
        $o->modelNamespace = 'Anorm\GraphQL\Test\Fixtures\Model';
        $o->outputDir = "$this->dir/src/Type";
        $o->typeNamespace = 'Made\Type';
        $o->testsDir = "$this->dir/tests";
        $o->testNamespace = 'Made\Tests';
        $o->schemaPath = "$this->dir/src/ApiSchema.php";
        $o->schemaNamespace = 'Made';
        return $o;
    }

    private function make(TypeMakerOptions $o): TypeMaker
    {
        $maker = new TypeMaker($o);
        $this->assertSame(0, $maker->run(), implode("\n", $maker->report));
        return $maker;
    }

    public function testWritesEveryFileForEachEntity(): void
    {
        $maker = $this->make($this->options());
        foreach (
            [
                'src/Type/Widget/Base/WidgetTypeBase.php',
                'src/Type/Widget/Base/WidgetInputBase.php',
                'src/Type/Widget/WidgetType.php',
                'src/Type/Widget/WidgetInput.php',
                'src/Type/Owner/OwnerType.php',
                'tests/WidgetTypeTest.php',
                'tests/TestCase.php',
                'src/ApiSchema.php',
            ] as $file
        ) {
            $this->assertFileExists("$this->dir/$file");
        }
        $report = implode("\n", $maker->report);
        $this->assertStringContainsString('LedgerLineModel', $report, 'the composite-key model is reported as skipped');
        $this->assertDirectoryDoesNotExist("$this->dir/src/Type/LedgerLine");
    }

    public function testReadOnlyEntityGetsNoInputAndNoMutations(): void
    {
        $o = $this->options();
        $o->readOnly = ['OwnerModel'];
        $this->make($o);
        $this->assertFileExists("$this->dir/src/Type/Owner/OwnerType.php");
        $this->assertFileDoesNotExist("$this->dir/src/Type/Owner/OwnerInput.php");
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        $this->assertStringContainsString("'ownerList'", $schema);
        $this->assertStringNotContainsString("'ownerUpsert'", $schema);
        $this->assertStringContainsString("'widgetUpsert'", $schema);
    }

    public function testOnlyLimitsTheRunAndDoesNotReportTheRestAsOrphans(): void
    {
        $this->make($this->options());
        $o = $this->options();
        $o->only = ['Widget'];
        $maker = $this->make($o);
        $this->assertStringNotContainsString('orphaned', implode("\n", $maker->report));
    }

    public function testAnUnknownNameIsAnErrorAndWritesNothing(): void
    {
        $o = $this->options();
        $o->only = ['Nope'];
        $maker = new TypeMaker($o);
        $this->assertSame(2, $maker->run());
        $this->assertStringContainsString("--only names 'Nope'", $maker->report[0]);
        $this->assertDirectoryDoesNotExist("$this->dir/src");
    }

    public function testSecondRunKeepsHandEditsAndRegeneratesBases(): void
    {
        $this->make($this->options());
        $mine = "$this->dir/src/Type/Widget/WidgetType.php";
        $base = "$this->dir/src/Type/Widget/Base/WidgetTypeBase.php";
        file_put_contents($mine, file_get_contents($mine) . "// my edit\n");
        $original = file_get_contents($base);
        file_put_contents($base, str_replace("'quantity'", "'stale'", $original));

        $this->make($this->options());
        $this->assertStringContainsString('// my edit', file_get_contents($mine));
        $this->assertSame($original, file_get_contents($base));
    }

    public function testForceOverwritesOnceOnlyFiles(): void
    {
        $this->make($this->options());
        $mine = "$this->dir/src/Type/Widget/WidgetType.php";
        file_put_contents($mine, "<?php // replaced\n");
        $o = $this->options();
        $o->force = true;
        $this->make($o);
        $this->assertStringContainsString('class WidgetType extends', file_get_contents($mine));
    }

    public function testDryRunWritesNothing(): void
    {
        $o = $this->options();
        $o->dryRun = true;
        $this->make($o);
        $this->assertDirectoryDoesNotExist("$this->dir/src");
        $this->assertDirectoryDoesNotExist("$this->dir/tests");
    }

    public function testOrphanedBaseFilesAreReportedNotDeleted(): void
    {
        $this->make($this->options());
        $orphan = "$this->dir/src/Type/Gone/Base/GoneTypeBase.php";
        mkdir(dirname($orphan), 0777, true);
        file_put_contents($orphan, "<?php\n// GENERATED by anorm-graphql\n");
        $maker = $this->make($this->options());
        $this->assertStringContainsString("orphaned $orphan", implode("\n", $maker->report));
        $this->assertFileExists($orphan);
    }

    public function testSchemaNoneLeavesTheSchemaAloneAndTestsNoneWritesNoTests(): void
    {
        $o = $this->options();
        $o->schemaPath = null;
        $o->testsDir = null;
        $this->make($o);
        $this->assertFileDoesNotExist("$this->dir/src/ApiSchema.php");
        $this->assertDirectoryDoesNotExist("$this->dir/tests");
    }

    private function awkward(): TypeMakerOptions
    {
        $o = $this->options();
        $o->modelsDir = dirname(__DIR__) . '/Fixtures/AwkwardModel';
        $o->modelNamespace = 'Anorm\GraphQL\Test\Fixtures\AwkwardModel';
        return $o;
    }

    public function testTwoModelsWithOneEntityNameAreBothSkipped(): void
    {
        $report = implode("\n", $this->make($this->awkward())->report);
        $this->assertDirectoryDoesNotExist("$this->dir/src/Type/Gadget");
        $this->assertMatchesRegularExpression("/skipped .*AwkwardModel.Gadget: entity 'Gadget' is also produced by .*GadgetModel/", $report);
        $this->assertMatchesRegularExpression(
            "/skipped .*AwkwardModel.GadgetModel: entity 'Gadget' is also produced by .*AwkwardModel.Gadget;/",
            $report
        );
        $this->assertStringNotContainsString("'gadgetList'", file_get_contents("$this->dir/src/ApiSchema.php"));
        $this->assertFileExists("$this->dir/src/Type/Flag/FlagType.php", 'the other models are still generated');
    }

    public function testAnEntityNamedLikeAReservedWordIsSkipped(): void
    {
        $report = implode("\n", $this->make($this->awkward())->report);
        $this->assertDirectoryDoesNotExist("$this->dir/src/Type/List");
        $this->assertMatchesRegularExpression("/skipped .*ListModel: entity 'List' is not a name PHP 7.4 allows/", $report);
        $this->assertStringNotContainsString("'listList'", file_get_contents("$this->dir/src/ApiSchema.php"));
    }

    public function testNamesReservedOnlyAsClassNamesAreFineAsEntities(): void
    {
        $report = implode("\n", $this->make($this->awkward())->report);
        foreach (['Parent', 'Match'] as $entity) {
            $this->assertFileExists("$this->dir/src/Type/$entity/Base/{$entity}TypeBase.php");
            $this->assertStringNotContainsString("entity '$entity'", $report);
        }
        // Reserved words are ordinary property names: `type` and `list` become fields.
        $base = file_get_contents("$this->dir/src/Type/Match/Base/MatchTypeBase.php");
        $this->assertStringContainsString("FieldBuilder::create('type', Type::string())", $base);
        $this->assertStringContainsString("FieldBuilder::create('list', Type::int())", $base);
    }

    public function testFilesOfAnEntityThatCanNoLongerBeProducedAreReported(): void
    {
        // As an earlier version of the tool, or an earlier state of the models, would have left them.
        foreach (['List', 'Gadget'] as $entity) {
            $stale = "$this->dir/src/Type/$entity/Base/{$entity}TypeBase.php";
            mkdir(dirname($stale), 0777, true);
            file_put_contents($stale, "<?php\n\n// GENERATED by anorm-graphql — do not edit.\n");
        }
        $report = implode("\n", $this->make($this->awkward())->report);
        foreach (['List', 'Gadget'] as $entity) {
            $stale = "$this->dir/src/Type/$entity/Base/{$entity}TypeBase.php";
            $this->assertStringContainsString("orphaned $stale", $report);
            $this->assertFileExists($stale, 'reported, never deleted');
        }
    }

    public function testWhenEveryModelIsUnusableTheirSchemaEntriesAreStillReported(): void
    {
        $models = "$this->dir/models";
        mkdir("$models/Sub", 0777, true);
        $model = "<?php\n\nnamespace %s;\n\nclass ClientModel extends \\Anorm\\Model\n{\n    public \$id;\n    public \$name;\n\n"
            . "    public function __construct(\\PDO \$pdo)\n    {\n        parent::__construct(\$pdo, "
            . "\\Anorm\\DataMapper::create(\$pdo, 'clients', \\Anorm\\DataMapper::autoMap(\$this)));\n    }\n}\n";
        file_put_contents("$models/ClientModel.php", sprintf($model, 'Solo\\Models'));
        $o = $this->options();
        $o->modelsDir = $models;
        $o->modelNamespace = 'Solo\Models';
        $this->make($o);
        $this->assertStringContainsString("'clientList'", file_get_contents("$this->dir/src/ApiSchema.php"));

        // A second model with the same entity name arrives: now neither can be generated.
        file_put_contents("$models/Sub/ClientModel.php", sprintf($model, 'Solo\\Models\\Sub'));
        $report = implode("\n", $this->make($o)->report);
        $this->assertStringContainsString("orphaned: 'clientList' is marked as generated but no model produces it", $report);
        $this->assertStringContainsString("orphaned $this->dir/src/Type/Client/Base/ClientTypeBase.php", $report);
        $this->assertStringContainsString("'clientList'", file_get_contents("$this->dir/src/ApiSchema.php"), 'reported, never deleted');
    }

    public function testSchemaEntriesOfAnEntityThatCanNoLongerBeProducedAreReported(): void
    {
        $o = $this->awkward();
        $o->only = ['Flag'];
        $this->make($o);
        $schema = "$this->dir/src/ApiSchema.php";
        file_put_contents($schema, str_replace(['flagList', 'FlagType'], ['gadgetList', 'GadgetType'], file_get_contents($schema)));

        $report = implode("\n", $this->make($this->awkward())->report);
        $this->assertStringContainsString("orphaned: 'gadgetList' is marked as generated but no model produces it", $report);
    }

    /**
     * @dataProvider unusableNamespaces
     */
    public function testANamespaceThatCannotBeUsedIsAnErrorAndWritesNothing(string $option, string $property): void
    {
        $o = $this->options();
        $o->$property = 'Made\new\Thing';
        $maker = new TypeMaker($o);
        $this->assertSame(2, $maker->run());
        $this->assertStringContainsString("Error: $option '" . 'Made\new\Thing' . "' cannot be used: 'new'", $maker->report[0]);
        $this->assertDirectoryDoesNotExist("$this->dir/src");
    }

    /** @return array<string, array<int, string>> */
    public function unusableNamespaces(): array
    {
        return [
            'types' => ['--type-ns', 'typeNamespace'],
            'tests' => ['--test-ns', 'testNamespace'],
            'schema' => ['--schema-ns', 'schemaNamespace'],
        ];
    }

    public function testEveryGeneratedTypeBaseExtendsTheTypeBase(): void
    {
        $o = $this->options();
        $o->typeBase = ProjectModelType::class;
        $this->make($o);
        foreach (['Widget', 'Owner'] as $entity) {
            $base = file_get_contents("$this->dir/src/Type/$entity/Base/{$entity}TypeBase.php");
            $this->assertStringContainsString(
                "abstract class {$entity}TypeBase extends \\" . ProjectModelType::class . "\n",
                $base
            );
            $this->assertStringNotContainsString('use Anorm\GraphQL\ModelType;', $base);
        }
    }

    public function testWithoutATypeBaseTheOutputIsUnchanged(): void
    {
        $this->make($this->options());
        $base = file_get_contents("$this->dir/src/Type/Widget/Base/WidgetTypeBase.php");
        $this->assertStringContainsString("use Anorm\GraphQL\ModelType;\n", $base);
        $this->assertStringContainsString("abstract class WidgetTypeBase extends ModelType\n", $base);
    }

    /**
     * @dataProvider unusableTypeBases
     */
    public function testATypeBaseThatCannotBeUsedIsAnErrorAndWritesNothing(string $class, string $why): void
    {
        $o = $this->options();
        $o->typeBase = $class;
        $maker = new TypeMaker($o);
        $this->assertSame(2, $maker->run());
        $this->assertSame(["Error: --type-base '$class' $why"], $maker->report);
        $this->assertDirectoryDoesNotExist("$this->dir/src");
        $this->assertDirectoryDoesNotExist("$this->dir/tests");
    }

    /** @return array<string, array<int, string>> */
    public function unusableTypeBases(): array
    {
        return [
            'not a class name' => ['Made\Not A Class', 'is not a class name'],
            'empty' => ['', 'is not a class name'],
            'trailing separator' => ['Made\Base\\', 'is not a class name'],
            'trailing newline' => ["Made\\Base\n", 'is not a class name'],
            'not loadable' => ['Made\Missing\BaseType', 'cannot be loaded: it is not a class the autoloader can find'],
            'an interface' => [\GraphQL\Error\ClientAware::class, 'is not a class'],
            'not a ModelType' => [\ArrayObject::class, 'does not extend Anorm\GraphQL\ModelType'],
            'final' => [FinalModelType::class, 'is final'],
        ];
    }

    public function testEverythingGeneratedParses(): void
    {
        $this->make($this->options());
        $this->make($this->awkward());
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($files as $file) {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $exit);
            $this->assertSame(0, $exit, implode("\n", $output));
            $count++;
        }
        $this->assertGreaterThan(10, $count);
    }

    public function testAnEntityThatBecomesReadOnlyHasItsInputFilesReported(): void
    {
        $this->make($this->options());
        $o = $this->options();
        $o->readOnly = ['Widget'];
        $report = implode("\n", $this->make($o)->report);
        foreach (["$this->dir/src/Type/Widget/WidgetInput.php", "$this->dir/src/Type/Widget/Base/WidgetInputBase.php"] as $path) {
            $this->assertStringContainsString("orphaned $path ('Widget' is read-only now; not deleted)", $report);
            $this->assertFileExists($path);
        }
    }

    public function testTheWholeRunStaysInsideTheDirectoriesGiven(): void
    {
        $this->make($this->options());
        $outside = "$this->dir/precious.php";
        file_put_contents($outside, "<?php\n\n// GENERATED by anorm-graphql — do not edit.\n// precious\n");
        $base = "$this->dir/src/Type/Widget/Base/WidgetTypeBase.php";
        unlink($base);
        symlink($outside, $base);

        $report = implode("\n", $this->make($this->options())->report);
        $this->assertStringContainsString("refused  $base (is a symbolic link)", $report);
        $this->assertStringContainsString('// precious', file_get_contents($outside));
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->make($this->options());
        $maker = $this->make($this->options());
        foreach ($maker->report as $line) {
            $this->assertMatchesRegularExpression('/^(current|kept|skipped)/', $line);
        }
    }

    private function createUpdate(): TypeMakerOptions
    {
        $o = $this->options();
        $o->mutations = 'create-update';
        return $o;
    }

    public function testCreateUpdateWritesTwoInputsAndTheirMutations(): void
    {
        $this->make($this->createUpdate());
        foreach (
            [
                'src/Type/Widget/Base/WidgetCreateInputBase.php',
                'src/Type/Widget/Base/WidgetUpdateInputBase.php',
                'src/Type/Widget/WidgetCreateInput.php',
                'src/Type/Widget/WidgetUpdateInput.php',
            ] as $file
        ) {
            $this->assertFileExists("$this->dir/$file");
        }
        $this->assertFileDoesNotExist("$this->dir/src/Type/Widget/WidgetInput.php");
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        $expected = [
            "'widgetCreate'", "'widgetUpdate'", "'widgetDelete'",
            "'resolveCreate'", "'resolveUpdate'",
            'WidgetCreateInput::class', 'WidgetUpdateInput::class',
        ];
        foreach ($expected as $text) {
            $this->assertStringContainsString($text, $schema);
        }
        $this->assertStringNotContainsString("'widgetUpsert'", $schema);
        $this->assertStringNotContainsString("'ownerUpsert'", $schema);
    }

    public function testCreateUpdateLeavesReadOnlyEntitiesAlone(): void
    {
        $o = $this->createUpdate();
        $o->readOnly = ['Owner'];
        $this->make($o);
        $this->assertFileDoesNotExist("$this->dir/src/Type/Owner/OwnerCreateInput.php");
        $this->assertStringNotContainsString("'ownerCreate'", file_get_contents("$this->dir/src/ApiSchema.php"));
    }

    public function testSwitchingToCreateUpdateReportsTheOldInputAndEntryAndDeletesNothing(): void
    {
        $this->make($this->options());
        $report = implode("\n", $this->make($this->createUpdate())->report);
        foreach (["$this->dir/src/Type/Widget/WidgetInput.php", "$this->dir/src/Type/Widget/Base/WidgetInputBase.php"] as $path) {
            $this->assertStringContainsString("orphaned $path ('Widget' uses create-update mutations now; not deleted)", $report);
            $this->assertFileExists($path);
        }
        $this->assertStringContainsString("orphaned: 'widgetUpsert' is marked as generated but no model produces it", $report);
        $this->assertStringContainsString("'widgetUpsert'", file_get_contents("$this->dir/src/ApiSchema.php"));
    }

    public function testAnUnknownMutationsValueIsAnErrorAndWritesNothing(): void
    {
        $o = $this->options();
        $o->mutations = 'upsert,create';
        $maker = new TypeMaker($o);
        $this->assertSame(2, $maker->run());
        $this->assertSame(["Error: --mutations 'upsert,create' must be 'upsert' or 'create-update'"], $maker->report);
        $this->assertDirectoryDoesNotExist("$this->dir/src");
    }

    public function testASecondCreateUpdateRunChangesNothing(): void
    {
        $this->make($this->createUpdate());
        foreach ($this->make($this->createUpdate())->report as $line) {
            $this->assertMatchesRegularExpression('/^(current|kept|skipped)/', $line);
        }
    }

    public function testWithoutMutationsTheOutputIsUnchanged(): void
    {
        $this->make($this->options());
        $this->assertFileExists("$this->dir/src/Type/Widget/WidgetInput.php");
        $this->assertFileDoesNotExist("$this->dir/src/Type/Widget/WidgetCreateInput.php");
        $this->assertStringContainsString("'widgetUpsert'", file_get_contents("$this->dir/src/ApiSchema.php"));
    }

    public function testInputOnlyWritesTheInputAndNothingElse(): void
    {
        $o = $this->options();
        $o->inputOnly = ['Owner'];
        $this->make($o);
        $this->assertFileExists("$this->dir/src/Type/Owner/OwnerInput.php");
        $this->assertFileExists("$this->dir/src/Type/Owner/Base/OwnerInputBase.php");
        $this->assertFileDoesNotExist("$this->dir/src/Type/Owner/OwnerType.php");
        $this->assertFileDoesNotExist("$this->dir/tests/OwnerTypeTest.php");
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        $this->assertStringNotContainsString("'owner", $schema, 'no entry of any kind');
        $this->assertStringContainsString("'widgetUpsert'", $schema);
    }

    public function testInputOnlyAndReadOnlyGiveATypeAListAndInputs(): void
    {
        $o = $this->options();
        $o->mutations = 'create-update';
        $o->readOnly = ['Owner'];
        $o->inputOnly = ['Owner'];
        $report = implode("\n", $this->make($o)->report);
        $this->assertFileExists("$this->dir/src/Type/Owner/OwnerType.php");
        $this->assertFileExists("$this->dir/src/Type/Owner/OwnerCreateInput.php");
        $this->assertFileExists("$this->dir/src/Type/Owner/OwnerUpdateInput.php");
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        $this->assertStringContainsString("'ownerList'", $schema);
        $this->assertStringNotContainsString("'ownerCreate'", $schema);
        $this->assertStringNotContainsString("'ownerUpdate'", $schema);
        $this->assertStringNotContainsString('orphaned', $report, 'its inputs are produced, so not stale');
    }

    public function testAnUnknownInputOnlyNameIsAnErrorAndWritesNothing(): void
    {
        $o = $this->options();
        $o->inputOnly = ['Nope'];
        $maker = new TypeMaker($o);
        $this->assertSame(2, $maker->run());
        $this->assertStringContainsString("--input-only names 'Nope'", $maker->report[0]);
        $this->assertDirectoryDoesNotExist("$this->dir/src");
    }

    public function testSwitchingToInputOnlyReportsTheOldTypeAndTestAndDeletesNothing(): void
    {
        $this->make($this->options());
        $o = $this->options();
        $o->inputOnly = ['Owner'];
        $report = implode("\n", $this->make($o)->report);
        foreach (
            [
                "$this->dir/src/Type/Owner/OwnerType.php",
                "$this->dir/src/Type/Owner/Base/OwnerTypeBase.php",
                "$this->dir/tests/OwnerTypeTest.php",
            ] as $path
        ) {
            $this->assertStringContainsString("orphaned $path ('Owner' is input-only now; not deleted)", $report);
            $this->assertFileExists($path);
        }
    }

    private function withoutUpdate(array $names): TypeMakerOptions
    {
        $o = $this->createUpdate();
        $o->withoutUpdate = $names;
        return $o;
    }

    public function testWithoutUpdateNeedsCreateUpdateMutations(): void
    {
        $o = $this->options();
        $o->withoutUpdate = ['Widget'];
        $maker = new TypeMaker($o);
        $this->assertSame(2, $maker->run());
        $this->assertStringContainsString(
            "--without-update names 'Widget', which needs --mutations create-update, not 'upsert'",
            $maker->report[0]
        );
        $this->assertDirectoryDoesNotExist("$this->dir/src");
    }

    public function testWithoutUpdateWritesNoUpdateInputAndNoUpdateMutation(): void
    {
        $maker = $this->make($this->withoutUpdate(['Widget']));
        $this->assertFileExists("$this->dir/src/Type/Widget/WidgetCreateInput.php");
        $this->assertFileExists("$this->dir/src/Type/Widget/Base/WidgetCreateInputBase.php");
        $this->assertFileDoesNotExist("$this->dir/src/Type/Widget/WidgetUpdateInput.php");
        $this->assertFileDoesNotExist("$this->dir/src/Type/Widget/Base/WidgetUpdateInputBase.php");
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        $this->assertStringContainsString("'widgetCreate'", $schema);
        $this->assertStringContainsString("'widgetDelete'", $schema);
        $this->assertStringNotContainsString("'widgetUpdate'", $schema);
        $this->assertStringNotContainsString('WidgetUpdateInput', $schema);
        // Owner is untouched: still both create and update.
        $this->assertStringContainsString("'ownerCreate'", $schema);
        $this->assertStringContainsString("'ownerUpdate'", $schema);
        $this->assertStringNotContainsString('orphaned', implode("\n", $maker->report));
    }

    public function testAnUnknownWithoutUpdateNameIsAnErrorAndWritesNothing(): void
    {
        $maker = new TypeMaker($this->withoutUpdate(['Nope']));
        $this->assertSame(2, $maker->run());
        $this->assertStringContainsString("--without-update names 'Nope'", $maker->report[0]);
        $this->assertDirectoryDoesNotExist("$this->dir/src");
    }

    public function testSwitchingToWithoutUpdateReportsTheOldUpdateInputAndDeletesNothing(): void
    {
        $this->make($this->createUpdate());
        $report = implode("\n", $this->make($this->withoutUpdate(['Widget']))->report);
        foreach (
            [
                "$this->dir/src/Type/Widget/WidgetUpdateInput.php",
                "$this->dir/src/Type/Widget/Base/WidgetUpdateInputBase.php",
            ] as $path
        ) {
            $this->assertStringContainsString(
                "orphaned $path ('Widget' is generated without an update mutation now; not deleted)",
                $report
            );
            $this->assertFileExists($path);
        }
        $this->assertStringContainsString("orphaned: 'widgetUpdate' is marked as generated but no model produces it", $report);
        $this->assertStringContainsString("'widgetUpdate'", file_get_contents("$this->dir/src/ApiSchema.php"), 'reported, never deleted');
    }

    public function testWithoutDeleteWorksUnderUpsert(): void
    {
        $o = $this->options();
        $o->withoutDelete = ['Widget'];
        $maker = $this->make($o);
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        $this->assertStringContainsString("'widgetUpsert'", $schema);
        $this->assertStringNotContainsString("'widgetDelete'", $schema);
        $this->assertStringContainsString("'ownerDelete'", $schema);
        $this->assertStringNotContainsString('orphaned', implode("\n", $maker->report));
    }

    public function testWithoutDeleteWorksUnderCreateUpdate(): void
    {
        $o = $this->createUpdate();
        $o->withoutDelete = ['Widget'];
        $maker = $this->make($o);
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        $this->assertStringContainsString("'widgetCreate'", $schema);
        $this->assertStringContainsString("'widgetUpdate'", $schema);
        $this->assertStringNotContainsString("'widgetDelete'", $schema);
    }

    public function testAnUnknownWithoutDeleteNameIsAnErrorAndWritesNothing(): void
    {
        $o = $this->options();
        $o->withoutDelete = ['Nope'];
        $maker = new TypeMaker($o);
        $this->assertSame(2, $maker->run());
        $this->assertStringContainsString("--without-delete names 'Nope'", $maker->report[0]);
        $this->assertDirectoryDoesNotExist("$this->dir/src");
    }

    public function testSwitchingToWithoutDeleteReportsTheOldSchemaEntryAndDeletesNothing(): void
    {
        $this->make($this->options());
        $o = $this->options();
        $o->withoutDelete = ['Widget'];
        $report = implode("\n", $this->make($o)->report);
        $this->assertStringContainsString("orphaned: 'widgetDelete' is marked as generated but no model produces it", $report);
        $this->assertStringContainsString("'widgetDelete'", file_get_contents("$this->dir/src/ApiSchema.php"), 'reported, never deleted');
    }

    public function testWithoutUpdateAndWithoutDeleteTogetherLeaveOnlyCreate(): void
    {
        $o = $this->createUpdate();
        $o->withoutUpdate = ['Widget'];
        $o->withoutDelete = ['Widget'];
        $this->make($o);
        $schema = file_get_contents("$this->dir/src/ApiSchema.php");
        $this->assertStringContainsString("'widgetCreate'", $schema);
        $this->assertStringNotContainsString("'widgetUpdate'", $schema);
        $this->assertStringNotContainsString("'widgetDelete'", $schema);
    }

    public function testWithoutUpdateOnAReadOnlyEntityIsHarmless(): void
    {
        // A read-only entity already has no update mutation; naming it is redundant,
        // not a conflict, since --mutations create-update is what makes it legal at all.
        $o = $this->createUpdate();
        $o->readOnly = ['Owner'];
        $o->withoutUpdate = ['Owner'];
        $maker = $this->make($o);
        $this->assertStringNotContainsString('Error', implode("\n", $maker->report));
    }
}
