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
        // Fully qualified, this line is 130 columns even for the short entity 'Client', so
        // it is written PSR-12 style like any other entry too long for one line.
        $this->assertStringContainsString(
            "\\Anorm\\GraphQL\\GraphQLUtils::createListField(\n    'clientList',\n",
            $result->paste['query'][0]
        );
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

    public function testWithNoEntitiesAtAllLeftoverEntriesAreStillReported(): void
    {
        // Every model of a run can turn out unusable: two of them sharing one entity name, say.
        $first = $this->edit($this->schema(''), [$this->info('Client')]);
        $none = $this->edit($first->source, [], []);
        $this->assertFalse($none->failed);
        $this->assertSame($first->source, $none->source, 'nothing to write, so nothing changes');
        $this->assertSame(
            [
                "orphaned: 'clientList' is marked as generated but no model produces it",
                "orphaned: 'clientDelete' is marked as generated but no model produces it",
                "orphaned: 'clientUpsert' is marked as generated but no model produces it",
            ],
            $none->messages
        );
        $this->assertSame([], $this->edit($first->source, [], ['Client'])->messages, 'still produced, merely not in this run');
    }

    public function testWithNoEntitiesAFileItCannotReadIsLeftInPeace(): void
    {
        $source = $this->fixture('unparseable.in');
        $result = $this->edit($source, [], []);
        $this->assertFalse($result->failed, 'nothing was going to be written, so nothing was refused');
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->messages);
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

    // ---- wrapping entries too long for one line ----

    /**
     * 'PaymentTerms' is the shortest entity name that, at the schema()/entry() helpers'
     * 20-space indentation, pushes every kind's first line past 120 columns; its Upsert
     * argument line goes over too, exercising both places entryLines() can wrap.
     */
    private function assertNoLineExceeds120(string $source): void
    {
        foreach (explode("\n", $source) as $i => $line) {
            $this->assertLessThanOrEqual(120, \strlen(\rtrim($line, "\r")), 'line ' . ($i + 1) . " exceeds 120 columns: [$line]");
        }
    }

    public function testALongEntryIsWrittenWrapped(): void
    {
        $source = $this->schema('');
        $result = $this->edit($source, [$this->info('PaymentTerms')]);
        $this->assertSoundEdit($source, $result, [$this->info('PaymentTerms')]);
        $this->assertNoLineExceeds120($result->source);
        $this->assertStringContainsString(
            "                    // anorm-graphql\n"
                . "                    GraphQLUtils::createListField(\n"
                . "                        'paymentTermsList',\n"
                . "                        \$this->type(PaymentTermsType::class),\n"
                . "                        'resolveList'\n"
                . "                    )\n"
                . "                        ->addArgument('query', \$this->type(MangoInput::class))\n"
                . "                        ->build(),\n",
            $result->source,
            'the first line wraps PSR-12 style; the argument line fits, so it does not'
        );
        $this->assertStringContainsString(
            "                        ->addArgument(\n"
                . "                            'input',\n"
                . "                            Type::nonNull(Type::listOf(Type::nonNull(\$this->type(PaymentTermsInput::class))))\n"
                . "                        )\n",
            $result->source,
            "paymentTermsUpsert's addArgument call is itself long enough to need wrapping too"
        );
    }

    public function testASecondRunOfAWrappedEntryReportsCurrent(): void
    {
        $infos = [$this->info('PaymentTerms')];
        $first = $this->edit($this->schema(''), $infos);
        $this->assertFalse($first->failed, \implode("\n", $first->messages));
        $second = $this->edit($first->source, $infos);
        $this->assertSame([], $second->messages);
        $this->assertSame($first->source, $second->source, 'a second run must change nothing, i.e. report current');
    }

    public function testAWrappedEntryIsUpdatedAndPlacedCorrectlyAmongShortOnes(): void
    {
        $withMutations = $this->edit($this->schema(''), [$this->info('PaymentTerms')]);
        $this->assertFalse($withMutations->failed, \implode("\n", $withMutations->messages));

        // Placed alphabetically between two short entities added afterwards.
        $shortAndLong = [$this->info('Ant', true), $this->info('PaymentTerms'), $this->info('Zebra', true)];
        $placed = $this->edit($withMutations->source, $shortAndLong);
        $this->assertSoundEdit($withMutations->source, $placed, $shortAndLong);
        $this->assertMatchesRegularExpression('/antList.*paymentTermsList.*zebraList/s', $placed->source);

        // Updated: PaymentTerms turns read-only, so its wrapped mutation entries are
        // dropped (reported as orphans, never deleted) while the wrapped List entry is
        // rewritten in place and stays current.
        $readOnlyInfos = [$this->info('Ant', true), $this->info('PaymentTerms', true), $this->info('Zebra', true)];
        $readOnly = $this->edit($placed->source, $readOnlyInfos);
        $this->assertContains(
            "orphaned: 'paymentTermsDelete' is marked as generated but no model produces it",
            $readOnly->messages
        );
        $this->assertContains(
            "orphaned: 'paymentTermsUpsert' is marked as generated but no model produces it",
            $readOnly->messages
        );
        $this->assertStringContainsString(
            "GraphQLUtils::createListField(\n                        'paymentTermsList',",
            $readOnly->source
        );
        $this->assertNoLineExceeds120($readOnly->source);
        $again = $this->edit($readOnly->source, $readOnlyInfos);
        $this->assertSame($readOnly->source, $again->source, 'the trimmed-down file is current too');
    }

    public function testAHandWrittenWrappedEntryWithoutTheMarkerIsACollision(): void
    {
        // The same PSR-12-wrapped shape anorm-graphql itself would write, but never
        // marked as generated: a developer's own multi-line entry.
        $handWritten = "                    \$this->handWritten(\n"
            . "                        'paymentTermsList',\n"
            . "                        \$this->type(PaymentTermsType::class),\n"
            . "                        'mine'\n"
            . "                    )\n"
            . "                        ->addArgument('query', \$this->type(MangoInput::class))\n"
            . "                        ->build(),\n";
        $source = $this->schema($handWritten);
        $result = $this->edit($source, [$this->info('PaymentTerms')]);
        $this->assertSoundEdit($source, $result, [$this->info('PaymentTerms')]);
        $this->assertContains(
            "collision: 'paymentTermsList' already exists and is not marked as generated; left alone",
            $result->messages
        );
        $this->assertStringContainsString("'mine'", $result->source, 'the hand-written multi-line entry is left alone');
        $this->assertStringContainsString(
            "'paymentTermsDelete'",
            $result->source,
            'other entries for the same entity are still written despite the collision'
        );
    }

    public function testFullyQualifiedModeAlsoWraps(): void
    {
        // No import of GraphQLUtils: this hand-written call means App\GraphQL\GraphQLUtils,
        // so generated entries must be fully qualified instead, which is even longer.
        $mine = "                    GraphQLUtils::createField('aaa', \$this->type(AType::class), 'r')->build(),\n";
        $source = $this->schema($mine);
        $result = $this->edit($source, [$this->info('PaymentTerms')]);
        $this->assertSoundEdit($source, $result, [$this->info('PaymentTerms')]);
        $this->assertStringNotContainsString('use Anorm\GraphQL\GraphQLUtils;', $result->source);
        $this->assertStringContainsString("\\Anorm\\GraphQL\\GraphQLUtils::createListField(\n", $result->source);
        $this->assertNoLineExceeds120($result->source);
    }

    public function testALongEntryPastedByHandIsAlsoWrapped(): void
    {
        $source = $this->fixture('unparseable.in');
        $result = $this->edit($source, [$this->info('PaymentTerms')]);
        $this->assertFailsSafe($source, $result);
        $this->assertCount(1, $result->paste['query']);
        $this->assertCount(2, $result->paste['mutation']);
        // The List entry's only argument, MangoInput, is short even fully qualified: once
        // wrapped, every line of it fits.
        $this->assertStringContainsString("GraphQLUtils::createListField(\n", $result->paste['query'][0]);
        $this->assertNoLineExceeds120($result->paste['query'][0]);
        // Delete's and Upsert's arguments are themselves long, deeply nested Type:: chains
        // once fully qualified; wrapping the call is still the same PSR-12 treatment, even
        // though no amount of splitting the *call* shortens a single long argument's own text.
        $this->assertStringContainsString("::createListField(\n", $result->paste['mutation'][0]);
        $this->assertStringContainsString("addArgument(\n", $result->paste['mutation'][0]);
        $this->assertStringContainsString("::createListField(\n", $result->paste['mutation'][1]);
        $this->assertStringContainsString("addArgument(\n", $result->paste['mutation'][1]);
    }
}
