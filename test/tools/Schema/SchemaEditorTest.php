<?php

namespace Anorm\GraphQL\Test\Tools\Schema;

use Anorm\GraphQL\Tools\Schema\SchemaEditor;
use Anorm\GraphQL\Tools\Schema\SchemaScaffolder;
use Anorm\GraphQL\Tools\TypeInfo;
use PHPUnit\Framework\TestCase;

/**
 * SchemaEditor is the only part of the tool that edits hand-written code, so each
 * case is a whole file in, a whole file out: test/Fixtures/schema/<case>.in.php and
 * <case>.expected.php. To accept an intended change run with UPDATE_GOLDEN=1 and
 * review the diff in git.
 */
class SchemaEditorTest extends TestCase
{
    private function info(string $entity, bool $readOnly = false): TypeInfo
    {
        $info = new TypeInfo();
        $info->entity = $entity;
        $info->readOnly = $readOnly;
        return $info;
    }

    private function fixture(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 2) . "/Fixtures/schema/$name.php");
    }

    /**
     * @param TypeInfo[] $infos
     */
    private function assertEdit(string $case, array $infos): \Anorm\GraphQL\Tools\Schema\SchemaEditResult
    {
        $editor = new SchemaEditor('App\GraphQL\Type');
        $result = $editor->edit($this->fixture("$case.in"), $infos);
        $expectedPath = dirname(__DIR__, 2) . "/Fixtures/schema/$case.expected.php";
        if (getenv('UPDATE_GOLDEN')) {
            file_put_contents($expectedPath, $result->source);
        }
        $this->assertSame(file_get_contents($expectedPath), $result->source, "$case: edited source");
        $this->assertNotEmpty(token_get_all($result->source, TOKEN_PARSE), 'the edited file must still parse');

        $again = $editor->edit($result->source, $infos);
        $this->assertSame($result->source, $again->source, "$case: a second run must change nothing");
        return $result;
    }

    public function testAlphabeticalFile(): void
    {
        $result = $this->assertEdit('alphabetical', [$this->info('Client'), $this->info('Banana'), $this->info('Zulu', true)]);
        $this->assertFalse($result->failed);
        $this->assertSame(
            ["collision: 'clientList' already exists and is not marked as generated; left alone"],
            $result->messages
        );
        // bananaList lands between appleList and the comment that leads clientList.
        $this->assertMatchesRegularExpression('/appleList.*bananaList.*A hand-written list.*clientList.*zebraList.*zuluList/s', $result->source);
        $this->assertStringNotContainsString("'zuluUpsert'", $result->source, 'read-only: list only');
    }

    public function testUnsortedFileIsNeverReordered(): void
    {
        $result = $this->assertEdit('unsorted', [$this->info('Mango')]);
        $this->assertMatchesRegularExpression('/mangoList.*zebraList.*appleList/s', $result->source);
        $this->assertStringContainsString("\t\t\t\t\t// anorm-graphql\n\t\t\t\t\tGraphQLUtils::", $result->source, 'tabs are copied');
        $this->assertStringContainsString('"brackets ] and , in a string"', $result->source);
    }

    public function testOwnedEntriesAreRewrittenAndUnmarkedOnesLeftAlone(): void
    {
        $result = $this->assertEdit('owned', [$this->info('Client')]);
        $this->assertStringNotContainsString('anOldShape', $result->source);
        $this->assertStringContainsString('resolveMyOwnUpsert', $result->source);
        $this->assertStringContainsString("'goneList'", $result->source, 'an orphan is reported, never deleted');
        $this->assertContains("orphaned: 'goneList' is marked as generated but no model produces it", $result->messages);
        $this->assertContains("collision: 'clientUpsert' already exists and is not marked as generated; left alone", $result->messages);
        $this->assertSame(1, substr_count($result->source, 'use Anorm\GraphQL\GraphQLUtils;'), 'imports are not duplicated');
    }

    public function testUnexpectedStructureChangesNothing(): void
    {
        $source = $this->fixture('unparseable.in');
        $result = (new SchemaEditor('App\GraphQL\Type'))->edit($source, [$this->info('Client')]);
        $this->assertTrue($result->failed);
        $this->assertSame($source, $result->source);
        $this->assertStringContainsString("under 'query'", $result->messages[0]);
        $this->assertCount(3, $result->paste);
        $this->assertStringContainsString("'clientList'", $result->paste[0]);
    }

    public function testScaffoldedFileTakesEntries(): void
    {
        $scaffold = (new SchemaScaffolder())->render('App\GraphQL');
        $editor = new SchemaEditor('App\GraphQL\Type');
        $result = $editor->edit($scaffold, [$this->info('Client')]);
        $this->assertFalse($result->failed);
        $this->assertNotEmpty(token_get_all($result->source, TOKEN_PARSE), 'the edited file must still parse');
        $this->assertStringContainsString("                'fields' => [\n                    // anorm-graphql\n", $result->source);
        $this->assertStringContainsString(
            "use App\GraphQL\Type\Client\ClientInput;\nuse App\GraphQL\Type\Client\ClientType;\nuse DI\Container;",
            $result->source,
            'imports go in alphabetical position'
        );
        $this->assertSame($result->source, $editor->edit($result->source, [$this->info('Client')])->source);
    }

    public function testNoEntitiesMeansNoChange(): void
    {
        $source = $this->fixture('alphabetical.in');
        $this->assertSame($source, (new SchemaEditor('App\GraphQL\Type'))->edit($source, [])->source);
    }
}
