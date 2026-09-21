<?php

namespace Anorm\GraphQL\Test\Tools\Schema;

use Anorm\GraphQL\Test\SchemaProbe;
use Anorm\GraphQL\Tools\Schema\SchemaScaffolder;

/**
 * SchemaEditor is the only part of the tool that edits hand-written code. The
 * whole-file cases are test/Fixtures/schema/<case>.in.php and <case>.expected.php; to
 * accept an intended change run with UPDATE_GOLDEN=1 and review the diff in git.
 */
class SchemaEditorTest extends SchemaProbe
{
    private function fixture(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 2) . "/Fixtures/schema/$name.php");
    }

    /**
     * @param \Anorm\GraphQL\Tools\TypeInfo[] $infos
     */
    private function assertFixture(string $case, array $infos): \Anorm\GraphQL\Tools\Schema\SchemaEditResult
    {
        $source = $this->fixture("$case.in");
        $result = $this->edit($source, $infos);
        $expectedPath = dirname(__DIR__, 2) . "/Fixtures/schema/$case.expected.php";
        if (getenv('UPDATE_GOLDEN')) {
            file_put_contents($expectedPath, $result->source);
        }
        $this->assertSame(file_get_contents($expectedPath), $result->source, "$case: edited source");
        $this->assertSoundEdit($source, $result, $infos);
        return $result;
    }

    public function testAlphabeticalFile(): void
    {
        $result = $this->assertFixture('alphabetical', [$this->info('Client'), $this->info('Banana'), $this->info('Zulu', true)]);
        $this->assertSame(
            ["collision: 'clientList' already exists and is not marked as generated; left alone"],
            $result->messages
        );
        $this->assertMatchesRegularExpression(
            '/appleList.*bananaList.*A hand-written list.*clientList.*zebraList.*zuluList/s',
            $result->source
        );
        $this->assertStringNotContainsString("'zuluUpsert'", $result->source, 'read-only: list only');
    }

    public function testUnsortedFileIsNeverReordered(): void
    {
        $result = $this->assertFixture('unsorted', [$this->info('Mango')]);
        $this->assertMatchesRegularExpression('/mangoList.*zebraList.*appleList/s', $result->source);
        // The file uses GraphQLUtils without importing it, so that name is its own class's.
        $this->assertStringContainsString("\t\t\t\t\t// anorm-graphql\n\t\t\t\t\t\\Anorm\\GraphQL\\GraphQLUtils::", $result->source, 'tabs are copied');
        $this->assertStringNotContainsString('use Anorm\GraphQL\GraphQLUtils;', $result->source);
        $this->assertStringContainsString('"brackets ] and , in a string"', $result->source);
        // An entity called Mango has a MangoInput of its own, and the runtime's is
        // already imported under that name: the second is written out in full.
        $this->assertStringContainsString('$this->type(\App\GraphQL\Type\Mango\MangoInput::class)', $result->source);
        $this->assertSame(1, preg_match_all('/^use .*\\\\MangoInput;$/m', $result->source));
    }

    public function testOwnedEntriesAreRewrittenAndUnmarkedOnesLeftAlone(): void
    {
        $result = $this->assertFixture('owned', [$this->info('Client')]);
        $this->assertStringNotContainsString('anOldShape', $result->source);
        $this->assertStringContainsString('resolveMyOwnUpsert', $result->source);
        $this->assertStringContainsString("'goneList'", $result->source, 'an orphan is reported, never deleted');
        $this->assertContains("orphaned: 'goneList' is marked as generated but no model produces it", $result->messages);
        $this->assertContains("collision: 'clientUpsert' already exists and is not marked as generated; left alone", $result->messages);
        $this->assertSame(1, substr_count($result->source, 'use Anorm\GraphQL\GraphQLUtils;'), 'imports are not duplicated');
    }

    public function testFieldsBuiltByAMethodCallChangeNothing(): void
    {
        $source = $this->fixture('unparseable.in');
        $result = $this->edit($source, [$this->info('Client')]);
        $this->assertFailsSafe($source, $result);
        $this->assertStringContainsString("'fields' under 'query' is not a literal", $result->messages[0]);
        $this->assertCount(1, $result->paste['query']);
        $this->assertCount(2, $result->paste['mutation']);
        $this->assertStringContainsString('\Anorm\GraphQL\GraphQLUtils::createListField(\'clientList\'', $result->paste['query'][0]);
    }

    public function testScaffoldedFileTakesEntries(): void
    {
        $scaffold = (new SchemaScaffolder())->render('App\GraphQL');
        $result = $this->edit($scaffold, [$this->info('Client')]);
        $this->assertSoundEdit($scaffold, $result, [$this->info('Client')]);
        $this->assertStringContainsString("                'fields' => [\n                    // anorm-graphql\n", $result->source);
        $this->assertStringContainsString(
            "use App\GraphQL\Type\Client\ClientInput;\nuse App\GraphQL\Type\Client\ClientType;\nuse DI\Container;",
            $result->source,
            'imports go in alphabetical position'
        );
    }

    public function testNoEntitiesMeansNoChange(): void
    {
        $source = $this->fixture('alphabetical.in');
        $this->assertSame($source, $this->edit($source, [])->source);
    }

    public function testAnEntityLeftOutOfThisRunIsNotAnOrphan(): void
    {
        $both = [$this->info('Client'), $this->info('Owner')];
        $first = $this->edit($this->schema(''), $both);
        $onlyClient = $this->edit($first->source, [$this->info('Client')], ['Client', 'Owner']);
        $this->assertSame([], $onlyClient->messages, '--only Client: Owner still exists');
        $this->assertSame($first->source, $onlyClient->source);

        $ownerGone = $this->edit($first->source, [$this->info('Client')], ['Client']);
        $this->assertCount(3, $ownerGone->messages);
        $this->assertStringContainsString("'ownerList'", $ownerGone->source, 'reported, never deleted');
    }

    public function testAnEntityThatBecomesReadOnlyHasItsMutationsReportedAsOrphans(): void
    {
        $first = $this->edit($this->schema(''), [$this->info('Client')]);
        $readOnly = $this->edit($first->source, [$this->info('Client', true)]);
        $this->assertSame(
            [
                "orphaned: 'clientDelete' is marked as generated but no model produces it",
                "orphaned: 'clientUpsert' is marked as generated but no model produces it",
            ],
            $readOnly->messages
        );
    }

    public function testTwoEntitiesWithTheSameFieldNamesAreReported(): void
    {
        $result = $this->edit($this->schema(''), [$this->info('Client'), $this->info('client')]);
        $this->assertSame(1, substr_count($result->source, "'clientList'"));
        $this->assertStringContainsString("skipped: 'client' would define 'clientList'", implode("\n", $result->messages));
    }
}
