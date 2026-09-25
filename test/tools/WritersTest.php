<?php

namespace Anorm\GraphQL\Test\Tools;

use Anorm\GraphQL\Test\Fixtures\CalendarModel\EventModel;
use Anorm\GraphQL\Test\Fixtures\Model\WidgetModel;
use Anorm\GraphQL\Test\Fixtures\Type\ProjectModelType;
use Anorm\GraphQL\Tools\NullPdo;
use Anorm\GraphQL\Tools\TypeInfo;
use Anorm\GraphQL\Tools\TypeInfoBuilder;
use Anorm\GraphQL\Tools\Writer\InputBaseWriter;
use Anorm\GraphQL\Tools\Writer\InputWriter;
use Anorm\GraphQL\Tools\Writer\TestCaseWriter;
use Anorm\GraphQL\Tools\Writer\TestWriter;
use Anorm\GraphQL\Tools\Writer\TypeBaseWriter;
use Anorm\GraphQL\Tools\Writer\TypeWriter;
use PHPUnit\Framework\TestCase;

/**
 * Each writer against a golden file. To accept an intended change, run with
 * UPDATE_GOLDEN=1 and review the diff of test/Fixtures/golden in git.
 */
class WritersTest extends TestCase
{
    private function info(bool $readOnly = false): TypeInfo
    {
        return (new TypeInfoBuilder())->build(new WidgetModel(new NullPdo()), $readOnly);
    }

    private function assertGolden(string $name, string $actual): void
    {
        $path = __DIR__ . '/../Fixtures/golden/' . $name . '.txt';
        if (getenv('UPDATE_GOLDEN')) {
            file_put_contents($path, $actual);
        }
        $this->assertFileExists($path);
        $this->assertSame(file_get_contents($path), $actual, "$name differs from its golden file");
        $this->assertValidPhp($actual, $name);
    }

    private function assertValidPhp(string $code, string $name): void
    {
        try {
            $this->assertNotEmpty(token_get_all($code, TOKEN_PARSE));
        } catch (\ParseError $e) {
            $this->fail("$name is not valid PHP: " . $e->getMessage());
        }
    }

    public function testTypeBase(): void
    {
        $this->assertGolden('WidgetTypeBase', (new TypeBaseWriter())->render($this->info(), 'App\GraphQL\Type'));
    }

    public function testTypeBaseOnAProjectBaseClass(): void
    {
        $this->assertGolden(
            'WidgetTypeBaseProjectBase',
            (new TypeBaseWriter())->render($this->info(), 'App\GraphQL\Type', ProjectModelType::class)
        );
    }

    public function testTheDefaultBaseNamedExplicitlyGivesTheDefaultOutput(): void
    {
        $default = (new TypeBaseWriter())->render($this->info(), 'App\GraphQL\Type');
        $this->assertSame($default, (new TypeBaseWriter())->render($this->info(), 'App\GraphQL\Type', 'Anorm\GraphQL\ModelType'));
        $this->assertSame($default, (new TypeBaseWriter())->render($this->info(), 'App\GraphQL\Type', '\Anorm\GraphQL\ModelType'));
    }

    public function testALeadingBackslashOnAProjectBaseIsNotDoubled(): void
    {
        $code = (new TypeBaseWriter())->render($this->info(), 'App\GraphQL\Type', '\\' . ProjectModelType::class);
        $this->assertStringContainsString('extends \Anorm\GraphQL\Test\Fixtures\Type\ProjectModelType' . "\n", $code);
        $this->assertStringNotContainsString('\\\\Anorm', $code);
    }

    public function testInputBase(): void
    {
        $this->assertGolden('WidgetInputBase', (new InputBaseWriter())->render($this->info(), 'App\GraphQL\Type'));
    }

    public function testType(): void
    {
        $this->assertGolden('WidgetType', (new TypeWriter())->render($this->info(), 'App\GraphQL\Type'));
    }

    public function testInput(): void
    {
        $this->assertGolden('WidgetInput', (new InputWriter())->render($this->info(), 'App\GraphQL\Type'));
    }

    public function testTest(): void
    {
        $this->assertGolden('WidgetTypeTest', (new TestWriter())->render($this->info(), 'App\GraphQL\Type', 'Tests\GraphQL'));
    }

    public function testReadOnlyTestHasNoInput(): void
    {
        $code = (new TestWriter())->render($this->info(true), 'App\GraphQL\Type', 'Tests\GraphQL');
        $this->assertStringContainsString('return null;', $code);
        $this->assertStringNotContainsString('WidgetInput', $code);
        $this->assertValidPhp($code, 'read-only test');
    }

    public function testAnEntityWithOnlyBooleansStillGetsSomethingToUpdate(): void
    {
        $model = new \Anorm\GraphQL\Test\Fixtures\AwkwardModel\FlagModel(new NullPdo());
        $code = (new TestWriter())->render((new TypeInfoBuilder())->build($model), 'App\GraphQL\Type', 'Tests\GraphQL');
        // Created as true, updated to false: an empty sampleUpdate() would skip the update step unnoticed.
        $this->assertMatchesRegularExpression("/function sampleUpdate\(\): array\s+\{\s+return \[\s+'active' => false,\s+\];/", $code);
        $this->assertValidPhp($code, 'Boolean-only test');
    }

    public function testAnEntityOfNothingButKeysSaysItsUpdateIsNotTested(): void
    {
        $model = new \Anorm\GraphQL\Test\Fixtures\AwkwardModel\LinkModel(new NullPdo());
        $code = (new TestWriter())->render((new TypeInfoBuilder())->build($model), 'App\GraphQL\Type', 'Tests\GraphQL');
        $this->assertStringContainsString('// Nothing to update could be chosen: every field is the key or a foreign key.', $code);
        $this->assertStringContainsString('the test reports itself incomplete', $code);
        $this->assertStringContainsString('add: ownerId, widgetId', $code);
        $this->assertValidPhp($code, 'keys-only test');
    }

    public function testAnUpdateFieldThatIsNotABooleanIsPreferred(): void
    {
        $code = (new TestWriter())->render($this->info(), 'App\GraphQL\Type', 'Tests\GraphQL');
        $this->assertMatchesRegularExpression("/function sampleUpdate\(\): array\s+\{\s+return \[\s+'name' => 'name 2',\s+\];/", $code);
    }

    public function testTestCase(): void
    {
        $this->assertGolden('TestCase', (new TestCaseWriter())->render('Tests\GraphQL', 'App\GraphQL\ApiSchema'));
    }

    public function testBaseFilesCarryTheGeneratedHeaderAndOnceOnlyFilesDoNot(): void
    {
        $header = '// GENERATED by anorm-graphql';
        $this->assertStringContainsString($header, (new TypeBaseWriter())->render($this->info(), 'A'));
        $this->assertStringContainsString($header, (new InputBaseWriter())->render($this->info(), 'A'));
        $this->assertStringNotContainsString($header, (new TypeWriter())->render($this->info(), 'A'));
        $this->assertStringNotContainsString($header, (new InputWriter())->render($this->info(), 'A'));
    }

    private function createUpdate(TypeInfo $info): TypeInfo
    {
        $info->mutations = 'create-update';
        return $info;
    }

    public function testCreateInputBaseHasNoKey(): void
    {
        $code = (new InputBaseWriter())->render($this->createUpdate($this->info()), 'App\GraphQL\Type', 'Create');
        $this->assertGolden('WidgetCreateInputBase', $code);
        $this->assertStringNotContainsString("create('id'", $code);
    }

    public function testUpdateInputBaseRequiresTheKeyAndNothingElse(): void
    {
        $code = (new InputBaseWriter())->render($this->createUpdate($this->info()), 'App\GraphQL\Type', 'Update');
        $this->assertGolden('WidgetUpdateInputBase', $code);
        $this->assertStringContainsString("FieldBuilder::create('id', Type::nonNull(Type::id()))->build(),", $code);
        $this->assertSame(1, substr_count($code, 'Type::nonNull('));
    }

    public function testCreateAndUpdateInputs(): void
    {
        $info = $this->createUpdate($this->info());
        $this->assertGolden('WidgetCreateInput', (new InputWriter())->render($info, 'App\GraphQL\Type', 'Create'));
        $this->assertGolden('WidgetUpdateInput', (new InputWriter())->render($info, 'App\GraphQL\Type', 'Update'));
    }

    public function testARequiredPropertyIsNonNullOnCreateOnly(): void
    {
        $info = $this->createUpdate((new TypeInfoBuilder())->build(new EventModel(new NullPdo())));
        $create = (new InputBaseWriter())->render($info, 'App\GraphQL\Type', 'Create');
        $this->assertGolden('EventCreateInputBase', $create);
        $this->assertStringContainsString("FieldBuilder::create('title', Type::nonNull(Type::string()))->build(),", $create);
        $update = (new InputBaseWriter())->render($info, 'App\GraphQL\Type', 'Update');
        $this->assertStringContainsString("FieldBuilder::create('title', Type::string())->build(),", $update);
    }

    public function testTheDefaultKindIsTheUpsertInput(): void
    {
        $info = $this->info();
        $this->assertSame(
            (new InputBaseWriter())->render($info, 'App\GraphQL\Type'),
            (new InputBaseWriter())->render($info, 'App\GraphQL\Type', '')
        );
    }

    public function testTypeUnderCreateUpdate(): void
    {
        $code = (new TypeWriter())->render($this->createUpdate($this->info()), 'App\GraphQL\Type');
        $this->assertGolden('WidgetTypeCreateUpdate', $code);
        $this->assertStringContainsString('resolveCreate / resolveUpdate', $code);
    }

    public function testTestUnderCreateUpdate(): void
    {
        $info = $this->createUpdate((new TypeInfoBuilder())->build(new EventModel(new NullPdo())));
        $code = (new TestWriter())->render($info, 'App\GraphQL\Type', 'Tests\GraphQL');
        $this->assertGolden('WidgetTypeTestCreateUpdate', $code);
        $this->assertStringContainsString('return EventCreateInput::class;', $code);
        $this->assertStringContainsString('return EventUpdateInput::class;', $code);
        $this->assertMatchesRegularExpression("/function requiredFields\(\): array\s+\{\s+return \[\s+'title',\s+\];/", $code);
    }
}
