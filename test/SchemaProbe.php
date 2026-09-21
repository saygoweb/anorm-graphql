<?php

namespace Anorm\GraphQL\Test;

use Anorm\GraphQL\Tools\Schema\SchemaEditor;
use Anorm\GraphQL\Tools\Schema\SchemaEditResult;
use Anorm\GraphQL\Tools\TypeInfo;
use PHPUnit\Framework\TestCase;

/**
 * What the schema editor tests share: building a schema file around a fields array,
 * running the editor, and the checks every successful edit must pass.
 */
abstract class SchemaProbe extends TestCase
{
    protected const HEADER = "<?php\n\nnamespace App\\GraphQL;\n\nuse GraphQL\\Type\\Schema;\n";

    protected function info(string $entity, bool $readOnly = false): TypeInfo
    {
        $info = new TypeInfo();
        $info->entity = $entity;
        $info->readOnly = $readOnly;
        return $info;
    }

    /**
     * A schema file. $query and $mutation are the inside of each fields array, already
     * indented by 20 spaces and ending in a newline; '' is an empty array on one line.
     */
    protected function schema(string $query, string $mutation = '', string $header = self::HEADER, string $classBody = ''): string
    {
        $fields = function (string $inside): string {
            return $inside === '' ? '[]' : "[\n" . $inside . '                ]';
        };
        return $header . "\nclass ApiSchema extends Schema\n{\n" . $classBody
            . "    public function __construct(\$context)\n    {\n        parent::__construct([\n"
            . "            'query' => new ObjectType([\n                'name' => 'Query',\n"
            . "                'fields' => " . $fields($query) . ",\n            ]),\n"
            . "            'mutation' => new ObjectType([\n                'name' => 'Mutation',\n"
            . "                'fields' => " . $fields($mutation) . ",\n            ]),\n"
            . "        ]);\n    }\n}\n";
    }

    protected function entry(string $name, string $suffix = ','): string
    {
        return "                    \$this->handWritten('$name', \$this->type(X::class), 'r')->build()$suffix\n";
    }

    /**
     * @param TypeInfo[] $infos
     * @param string[]|null $known Defaults to the entities of $infos
     */
    protected function edit(string $source, array $infos, ?array $known = null): SchemaEditResult
    {
        if ($known === null) {
            $known = array_map(function (TypeInfo $info) {
                return $info->entity;
            }, $infos);
        }
        return (new SchemaEditor('App\GraphQL\Type'))->edit($source, $infos, $known);
    }

    /**
     * What must hold after any edit that did not fail: the file compiles (names
     * included, which a syntax check alone does not show), every line of the original
     * is still there in order, and a second run changes nothing.
     *
     * @param TypeInfo[] $infos
     */
    protected function assertSoundEdit(string $source, SchemaEditResult $result, array $infos, ?array $known = null): void
    {
        $this->assertFalse($result->failed, implode("\n", $result->messages));
        $this->assertCompiles($result->source);
        $this->assertLinesSurvive($source, $result->source);
        $again = $this->edit($result->source, $infos, $known);
        $this->assertSame($result->source, $again->source, 'a second run must change nothing');
    }

    protected function assertFailsSafe(string $source, SchemaEditResult $result): void
    {
        $this->assertTrue($result->failed, 'expected the editor to refuse this file');
        $this->assertSame($source, $result->source, 'a refused file must be returned byte for byte');
        $this->assertStringStartsWith('ApiSchema not changed:', $result->messages[count($result->messages) - 1]);
    }

    /** `php -l`, which resolves imports and so catches a name bound twice. */
    protected function assertCompiles(string $code): void
    {
        $dir = dirname(__DIR__) . '/build/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = tempnam($dir, 'lint');
        file_put_contents($file, $code);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
        unlink($file);
        $this->assertSame(0, $exit, implode("\n", $output) . "\n" . $code);
    }

    /** Every line of the original, owned entries aside, must appear in the result in the same order. */
    protected function assertLinesSurvive(string $before, string $after): void
    {
        $afterLines = explode("\n", $after);
        $at = 0;
        $owned = false;
        foreach (explode("\n", $before) as $line) {
            if (trim($line) === '// anorm-graphql') {
                $owned = true;
                continue;
            }
            if ($owned) {
                // The lines of an owned entry may be rewritten; it ends at its ->build(), line.
                $owned = strpos($line, '->build()') === false;
                continue;
            }
            $found = false;
            for ($i = $at, $n = count($afterLines); $i < $n; $i++) {
                $same = $afterLines[$i] === $line;
                $gainedComma = preg_replace('#\)(\s*(//|/\*).*)?$#', '),$1', $line, 1) === $afterLines[$i];
                // An empty array written on one line opens up: `=> [],` becomes `=> [`.
                $openedUp = preg_match('#^(.*\[)\s*\],?$#', $line, $m) === 1 && $afterLines[$i] === $m[1];
                if ($same || $gainedComma || $openedUp) {
                    $at = $i + 1;
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "a hand-written line was lost or moved: [$line]\n" . $after);
        }
    }
}
