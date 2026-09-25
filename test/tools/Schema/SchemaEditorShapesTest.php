<?php

namespace Anorm\GraphQL\Test\Tools\Schema;

use Anorm\GraphQL\Test\SchemaProbe;

/**
 * Hand-written schemas come in many shapes. For each, the editor must either make a
 * sound edit or refuse and change nothing; it must never write a file that does not
 * compile, put an entry in the wrong place, or lose a line of somebody's code.
 * Most of these cases came out of an adversarial review of the first implementation.
 */
class SchemaEditorShapesTest extends SchemaProbe
{
    // ---- where the fields arrays are ----

    public function testAnUnrelatedArrayWithTheSameKeysIsNotMistakenForTheSchema(): void
    {
        $constant = "    const REPORT = [\n        'query' => [\n            'sql' => 'SELECT 1',\n            'fields' => [\n"
            . "                'id',\n            ],\n        ],\n        'mutation' => [\n            'fields' => [\n                'a',\n"
            . "            ],\n        ],\n    ];\n\n";
        $source = $this->schema($this->entry('aaa'), $this->entry('aaa'), self::HEADER, $constant);
        $result = $this->edit($source, [$this->info('Client')]);
        $this->assertSoundEdit($source, $result, [$this->info('Client')]);
        $this->assertStringContainsString("'fields' => [\n                'id',\n            ],", $result->source, 'the constant is untouched');
        $this->assertMatchesRegularExpression("/'name' => 'Query'.*clientList.*'name' => 'Mutation'.*clientDelete.*clientUpsert/s", $result->source);
    }

    public function testAStrayMutationKeyDoesNotSendMutationsToTheQueryArray(): void
    {
        $source = $this->schema($this->entry('aaa'), $this->entry('aaa'), self::HEADER, "    private \$defaults = ['mutation' => true];\n\n");
        $result = $this->edit($source, [$this->info('Zebra')]);
        $this->assertSoundEdit($source, $result, [$this->info('Zebra')]);
        $query = substr($result->source, 0, strpos($result->source, "'name' => 'Mutation'"));
        $this->assertStringContainsString("'zebraList'", $query);
        $this->assertStringNotContainsString("'zebraDelete'", $query, 'a mutation must never land in Query');
    }

    public function testTwoQueryObjectTypesAreAmbiguousSoNothingChanges(): void
    {
        $source = $this->schema($this->entry('aaa'))
            . "\n\$other = ['query' => new ObjectType(['name' => 'Q2', 'fields' => [\n]])];\n";
        $this->assertFailsSafe($source, $this->edit($source, [$this->info('Client')]));
    }

    /**
     * @dataProvider unsupportedFieldsForms
     */
    public function testAFormOfFieldsItDoesNotHandleChangesNothing(string $from, string $to): void
    {
        $source = str_replace($from, $to, $this->schema(''));
        $this->assertNotSame($this->schema(''), $source, 'the case must actually alter the schema');
        $this->assertFailsSafe($source, $this->edit($source, [$this->info('Client')]));
    }

    /** @return array<string, array<int, string>> */
    public function unsupportedFieldsForms(): array
    {
        $empty = "'name' => 'Query',\n                'fields' => []";
        return [
            'array() syntax' => [$empty, "'name' => 'Query',\n                'fields' => array()"],
            'a closure' => [$empty, "'name' => 'Query',\n                'fields' => function () {\n                    return [];\n                }"],
            'an arrow function' => [$empty, "'name' => 'Query',\n                'fields' => fn () => []"],
            'not an ObjectType' => ["'query' => new ObjectType([", "'query' => \$this->queryType(["],
            'two entries on one line' => [$empty, "'name' => 'Query',\n                'fields' => [\n                    \$a, \$b,\n                ]"],
            'entries on the bracket line' => [$empty, "'name' => 'Query',\n                'fields' => [\$a]"],
            'closing bracket on an entry line' => [$empty, "'name' => 'Query',\n                'fields' => [\n                    \$a]"],
        ];
    }

    public function testASchemaWithNoMutationTypeTakesReadOnlyEntities(): void
    {
        $source = preg_replace("/            'mutation' => new ObjectType\(\[.*?\]\),\n        \]\);/s", '        ]);', $this->schema(''));
        $this->assertStringNotContainsString('mutation', $source);

        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Client', true)]);
        $this->assertStringContainsString("'clientList'", $result->source);

        $this->assertFailsSafe($source, $this->edit($source, [$this->info('Client')]));
    }

    // ---- entries ----

    public function testALastEntryWithoutACommaGetsOneWhateverItLooksLike(): void
    {
        foreach (["                    ...\$this->extraFields()\n", $this->entry('aaa', ''), $this->entry('aaa', ' // last, no comma')] as $last) {
            $source = $this->schema($this->entry('aab') . $last);
            $result = $this->edit($source, [$this->info('Zulu', true)]);
            $this->assertSoundEdit($source, $result, [$this->info('Zulu', true)]);
            $this->assertStringContainsString("\n                ],\n            ]),\n            'mutation'", $result->source, 'the closing bracket keeps its indentation');
        }
        $this->assertStringContainsString("->build(), // last, no comma\n", $result->source, 'the comma goes before the trailing comment');
    }

    public function testAnOrphanedLastEntryWithoutACommaStillGetsOne(): void
    {
        $orphan = "                    // anorm-graphql\n                    \$this->generatedOnce('goneList', \$this->type(Gone::class))\n";
        $source = $this->schema($this->entry('aaa') . $orphan);
        $result = $this->edit($source, [$this->info('Zulu', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Zulu', true)]);
        $this->assertContains("orphaned: 'goneList' is marked as generated but no model produces it", $result->messages);
        $this->assertStringContainsString("\$this->type(Gone::class)),\n", $result->source);
    }

    public function testTwoIdenticalLastEntriesDoNotConfuseTheCommaRepair(): void
    {
        $source = $this->schema($this->entry('aaa') . $this->entry('aaa', ''));
        $result = $this->edit($source, [$this->info('Zulu', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Zulu', true)]);
    }

    public function testAnOwnedEntryWithoutAReadableNameIsReportedAsSuch(): void
    {
        $source = $this->schema("                    // anorm-graphql\n                    \$this->mystery(),\n");
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Client', true)]);
        $this->assertSame(['orphaned: an entry is marked as generated but its field name cannot be read'], $result->messages);
    }

    public function testTheSameNameMarkedTwiceIsReportedAndOnlyTheFirstRewritten(): void
    {
        $owned = "                    // anorm-graphql\n                    \$this->old('clientList'),\n";
        $source = $this->schema($owned . $owned);
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertFalse($result->failed);
        $this->assertSame(1, substr_count($result->source, "createListField('clientList'"));
        $this->assertSame(1, substr_count($result->source, "\$this->old('clientList')"));
        $this->assertStringContainsString("duplicate: 'clientList'", implode("\n", $result->messages));
        $this->assertSame($result->source, $this->edit($result->source, [$this->info('Client', true)])->source);
    }

    public function testACommentBetweenTheMarkerAndTheCodeMakesTheEntryHandWritten(): void
    {
        $note = "                    // anorm-graphql\n                    // a note the developer added underneath the marker\n" . $this->entry('clientList');
        $source = $this->schema($note);
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Client', true)]);
        $this->assertStringContainsString('a note the developer added', $result->source);
        $this->assertContains("collision: 'clientList' already exists and is not marked as generated; left alone", $result->messages);
    }

    /**
     * @dataProvider markerLookalikes
     */
    public function testOnlyTheExactMarkerMeansOwned(string $comment): void
    {
        $source = $this->schema("                    $comment\n" . $this->entry('clientList'));
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Client', true)]);
        $this->assertStringContainsString("handWritten('clientList'", $result->source, 'not rewritten');
    }

    /** @return array<string, array<int, string>> */
    public function markerLookalikes(): array
    {
        return ['block comment' => ['/* anorm-graphql */'], 'no space' => ['//anorm-graphql'], 'more words' => ['// anorm-graphql: client']];
    }

    public function testATrailingCommentStaysWithItsOwnEntry(): void
    {
        $query = $this->entry('aaa', ', // keep me') . $this->entry('bbb', ', /* and me */')
            . "                    /** doc comment for ddd */\n" . $this->entry('ddd');
        $source = $this->schema($query);
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Client', true)]);
        $this->assertMatchesRegularExpression('#bbb.*/\* and me \*/\n\s+// anorm-graphql\n.*clientList.*->build\(\),\n\s+/\*\* doc comment for ddd#s', $result->source);
    }

    public function testATrailingCommentOnAnOwnedEntrySurvivesTheRewrite(): void
    {
        $first = $this->edit($this->schema(''), [$this->info('Client', true)]);
        $annotated = str_replace("        ->build(),\n                ],\n            ]),\n            'mutation'", "        ->build(), // reviewed by me\n                ],\n            ]),\n            'mutation'", $first->source);
        $this->assertNotSame($first->source, $annotated);
        $again = $this->edit($annotated, [$this->info('Client', true)]);
        $this->assertSame($annotated, $again->source);
    }

    public function testArrayStyleAndKeyedEntriesAreReadByTheirFieldName(): void
    {
        $query = "                    ['name' => 'aardvark', 'type' => Type::string()],\n"
            . "                    ['name' => 'clientList', 'type' => Type::string(), 'resolve' => 'mine'],\n"
            . "                    'zebra' => ['type' => Type::string()],\n";
        $source = $this->schema($query);
        $infos = [$this->info('Client', true), $this->info('Monkey', true)];
        $result = $this->edit($source, $infos);
        $this->assertSoundEdit($source, $result, $infos);
        $this->assertSame(["collision: 'clientList' already exists and is not marked as generated; left alone"], $result->messages);
        $this->assertMatchesRegularExpression("/aardvark.*'mine'.*monkeyList.*'zebra'/s", $result->source);
    }

    public function testBracketsInsideStringsHeredocsAndClosuresAreNotCounted(): void
    {
        $query = "                    GraphQLUtils::createField('aaa', \$t, 'r')->setDescription(<<<TXT\n  ] , [ {\$x['k']} \${y}\nTXT\n"
            . "                    )->setResolver(function (\$r, \$a) { return [\$a['x'], \"] , {\$a['y']}\"]; })->build(),\n"
            . $this->entry('zzz');
        $source = $this->schema($query);
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Client', true)]);
        $this->assertMatchesRegularExpression("#'aaa'.*->build\(\),\n\s+// anorm-graphql\n.*clientList.*'zzz'#s", $result->source);
    }

    public function testAnAttributeInsideTheArrayDoesNotUnbalanceTheBrackets(): void
    {
        if (PHP_VERSION_ID < 80000) {
            // On 7.4 `#[...]` is a comment to the end of the line, so the attribute has a line to itself.
            $query = "                    'aaa' => [\n                        'resolve' =>\n                            #[SensitiveParameter]\n"
                . "                            function (\$root, \$args) { return 1; },\n                    ],\n" . $this->entry('zzz');
        } else {
            $query = "                    'aaa' => [\n                        'resolve' => #[SensitiveParameter] function (\$root, \$args) { return 1; },\n"
                . "                    ],\n" . $this->entry('zzz');
        }
        $source = $this->schema($query);
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Client', true)]);
        $this->assertMatchesRegularExpression("#'aaa' => \[.*\n                    \],\n\s+// anorm-graphql\n.*clientList.*'zzz'#s", $result->source);
    }

    /**
     * @dataProvider emptyArrays
     */
    public function testEveryWayOfWritingAnEmptyArray(string $inside): void
    {
        $source = str_replace("'name' => 'Query',\n                'fields' => []", "'name' => 'Query',\n                'fields' => [$inside]", $this->schema(''));
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Client', true)]);
        $this->assertStringContainsString("'clientList'", $result->source);
    }

    /** @return array<string, array<int, string>> */
    public function emptyArrays(): array
    {
        return [
            'nothing' => [''],
            'a space' => [' '],
            'a newline' => ["\n                "],
            'only a comment' => ["\n                    // nothing here yet\n                "],
        ];
    }

    // ---- line endings ----

    public function testACrlfFileStaysCrlf(): void
    {
        $source = str_replace("\n", "\r\n", $this->schema($this->entry('aaa'), $this->entry('aaa')));
        $result = $this->edit($source, [$this->info('Client')]);
        $this->assertFalse($result->failed, implode("\n", $result->messages));
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $result->source), 'no bare LF may be introduced');
        $this->assertCompiles($result->source);
        $this->assertSame($result->source, $this->edit($result->source, [$this->info('Client')])->source);
    }

    public function testOneStrayCrlfDoesNotMakeAnLfFileCrlf(): void
    {
        $source = str_replace("'name' => 'Query',\n", "'name' => 'Query',\r\n", $this->schema($this->entry('aaa')));
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertFalse($result->failed, implode("\n", $result->messages));
        $this->assertSame(1, substr_count($result->source, "\r"), 'inserted lines use the ending the file mostly uses');
    }

    public function testABomAndAMissingFinalNewlineAreLeftAsTheyAre(): void
    {
        $source = "\xEF\xBB\xBF" . rtrim($this->schema($this->entry('aaa')), "\n");
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertFalse($result->failed, implode("\n", $result->messages));
        $this->assertStringStartsWith("\xEF\xBB\xBF<?php", $result->source);
        $this->assertSame('}', substr($result->source, -1));
    }

    // ---- imports ----

    /**
     * @dataProvider headers
     * @param string[] $mustContain
     * @param string[] $mustNotContain
     */
    public function testImportsNeverClashWithWhatIsThere(string $header, array $mustContain, array $mustNotContain): void
    {
        $source = $this->schema($this->entry('aaa'), $this->entry('aaa'), $header);
        $result = $this->edit($source, [$this->info('Client')]);
        $this->assertSoundEdit($source, $result, [$this->info('Client')]);
        foreach ($mustContain as $text) {
            $this->assertStringContainsString($text, $result->source);
        }
        foreach ($mustNotContain as $text) {
            $this->assertStringNotContainsString($text, $result->source);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function headers(): array
    {
        $ns = "<?php\n\nnamespace App\\GraphQL;\n\n";
        return [
            'the same short name from elsewhere' => [
                $ns . "use Api\\GraphQL\\Type\\MangoInput;\nuse Other\\Type;\n",
                ['$this->type(\Anorm\GraphQL\Type\MangoInput::class)', '\GraphQL\Type\Definition\Type::nonNull('],
                ['use Anorm\GraphQL\Type\MangoInput;', 'use GraphQL\Type\Definition\Type;'],
            ],
            'a grouped import' => [
                $ns . "use GraphQL\\Type\\Definition\\{ObjectType, Type};\nuse DI\\Container;\n",
                ['Type::nonNull(Type::listOf('],
                ['use GraphQL\Type\Definition\Type;', '\GraphQL\Type\Definition\Type::'],
            ],
            'an aliased import of the very class' => [
                $ns . "use GraphQL\\Type\\Definition\\Type as GType;\n",
                ['GType::nonNull(GType::listOf('],
                ['use GraphQL\Type\Definition\Type;', ' Type::nonNull('],
            ],
            'declare and no namespace' => [
                "<?php\ndeclare(strict_types=1);\n",
                ["declare(strict_types=1);\n\nuse Anorm\\GraphQL\\GraphQLUtils;\n"],
                [],
            ],
            'nothing at all' => ["<?php\n", ["<?php\nuse Anorm\\GraphQL\\GraphQLUtils;\n"], []],
            'no use lines' => [$ns, ["namespace App\\GraphQL;\n\nuse Anorm\\GraphQL\\GraphQLUtils;\n"], []],
            'use function and use const' => [
                $ns . "use function strlen;\nuse const PHP_EOL;\nuse DI\\Container;\n",
                ['use Anorm\GraphQL\GraphQLUtils;'],
                [],
            ],
            'a class of the same name declared in the file' => [
                $ns . "use DI\\Container;\n\nclass Type\n{\n}\n",
                ['\GraphQL\Type\Definition\Type::nonNull('],
                ['use GraphQL\Type\Definition\Type;'],
            ],
        ];
    }

    public function testANameTheFileUsesFromItsOwnNamespaceIsNotShadowed(): void
    {
        // No import of GraphQLUtils: these references mean App\GraphQL\GraphQLUtils.
        $mine = "                    GraphQLUtils::createField('aaa', \$this->type(AType::class), 'r')->build(),\n";
        $source = $this->schema($mine, $mine);
        $result = $this->edit($source, [$this->info('Client')]);
        $this->assertSoundEdit($source, $result, [$this->info('Client')]);
        $this->assertStringNotContainsString('use Anorm\GraphQL\GraphQLUtils;', $result->source, 'that would repoint the hand-written calls');
        // Fully qualified, even this short entity name's line is 125 columns, so it wraps.
        $this->assertStringContainsString("\\Anorm\\GraphQL\\GraphQLUtils::createListField(\n                        'clientList',", $result->source);
        $this->assertStringContainsString("GraphQLUtils::createField('aaa'", $result->source);
    }

    public function testTheFirstPartOfAQualifiedNameIsANameInUseToo(): void
    {
        // `Type\Action\ActionType` means App\GraphQL\Type\Action\ActionType. Importing a class
        // called Type would repoint it. PHP 8 makes that name one token, 7.4 several.
        $mine = "                    \$this->handWritten('aaa', \$this->type(Type\\Action\\ActionType::class), 'r')->build(),\n";
        $source = $this->schema($mine, $mine);
        $result = $this->edit($source, [$this->info('Client')]);
        $this->assertSoundEdit($source, $result, [$this->info('Client')]);
        $this->assertStringNotContainsString('use GraphQL\Type\Definition\Type;', $result->source);
        $this->assertStringContainsString('\GraphQL\Type\Definition\Type::nonNull(', $result->source);
    }

    public function testAUseStatementSplitAcrossLinesStillBindsItsName(): void
    {
        $header = "<?php\n\nnamespace App\\GraphQL;\n\nuse GraphQL\\Type\\Definition\\\n    Type;\n";
        $source = $this->schema($this->entry('aaa'), $this->entry('aaa'), $header);
        $result = $this->edit($source, [$this->info('Client')]);
        if (PHP_VERSION_ID >= 80000) {
            // Whitespace inside a name stopped being legal in PHP 8: the file itself does not parse.
            $this->assertFailsSafe($source, $result);
            $this->assertStringContainsString('the file does not parse under PHP', $result->messages[0]);
            return;
        }
        $this->assertSoundEdit($source, $result, [$this->info('Client')]);
        $this->assertSame(0, preg_match('/^use GraphQL\\\\Type\\\\Definition\\\\Type;$/m', $result->source), 'already imported');
        $this->assertStringContainsString(' Type::nonNull(Type::listOf(', $result->source);
    }

    public function testAUseStatementItCannotReadMeansNothingIsImported(): void
    {
        // `use` of a namespace-relative name is legal and odd enough not to be understood.
        $header = "<?php\n\nnamespace App\\GraphQL;\n\nuse namespace\\Sub\\Thing;\n";
        $source = $this->schema($this->entry('aaa'), '', $header);
        $result = $this->edit($source, [$this->info('Client', true)]);
        if ($result->failed) {
            $this->assertFailsSafe($source, $result);
            return;
        }
        $this->assertLinesSurvive($source, $result->source);
        $this->assertSame(1, substr_count($result->source, "\nuse "), 'no import was added');
        $this->assertStringContainsString('\Anorm\GraphQL\GraphQLUtils::createListField(', $result->source);
    }

    public function testTwoNamespacesInOneFileGetFullyQualifiedNamesAndNoImports(): void
    {
        $body = substr($this->schema($this->entry('aaa'), $this->entry('aaa'), ''), 1);
        $source = "<?php\n\nnamespace App\\Other;\n\nuse DI\\Container;\n\nclass Helper\n{\n}\n\nnamespace App\\GraphQL;\n\nuse GraphQL\\Type\\Schema;\n" . $body;
        $result = $this->edit($source, [$this->info('Client')]);
        $this->assertSoundEdit($source, $result, [$this->info('Client')]);
        $this->assertSame(2, substr_count($result->source, "\nuse "), 'no import was added to either namespace');
        $this->assertStringContainsString('\App\GraphQL\Type\Client\ClientType::class', $result->source);
    }

    public function testABracedNamespaceGetsFullyQualifiedNamesAndNoImports(): void
    {
        $body = substr($this->schema($this->entry('aaa'), $this->entry('aaa'), ''), 1);
        $source = "<?php\n\nnamespace App\\GraphQL {\n    use GraphQL\\Type\\Schema;\n" . $body . "}\n";
        $result = $this->edit($source, [$this->info('Client')]);
        $this->assertSoundEdit($source, $result, [$this->info('Client')]);
        $this->assertStringContainsString('\Anorm\GraphQL\GraphQLUtils::createListField(', $result->source);
        $this->assertSame(1, substr_count($result->source, "\n    use "), 'no import was added');
    }

    public function testATraitUseAndAClosureUseAreNotImports(): void
    {
        $classBody = "    use SomeTrait;\n\n    private function f()\n    {\n        return function () use (\$x) {\n        };\n    }\n\n";
        $source = $this->schema($this->entry('aaa'), '', self::HEADER, $classBody);
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertSoundEdit($source, $result, [$this->info('Client', true)]);
        $this->assertStringContainsString("use Anorm\\GraphQL\\Type\\MangoInput;\nuse App\\GraphQL\\Type\\Client\\ClientType;\nuse GraphQL\\Type\\Schema;", $result->source);
    }

    public function testNothingInsertedMeansNoImportsAdded(): void
    {
        $source = $this->schema($this->entry('clientList'));
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertSame($source, $result->source, 'every field collided, so the file must be untouched');
        $this->assertCount(1, $result->messages);
    }

    // ---- scale ----

    public function testALargeArrayIsHandledInReasonableTime(): void
    {
        $query = '';
        for ($i = 0; $i < 3000; $i++) {
            $query .= $this->entry(sprintf('f%05d', $i));
        }
        $source = $this->schema($query);
        $started = microtime(true);
        $result = $this->edit($source, [$this->info('Client', true)]);
        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertFalse($result->failed);
        $this->assertStringContainsString("'clientList'", $result->source);
    }
}
