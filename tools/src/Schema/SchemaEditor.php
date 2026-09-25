<?php
namespace Anorm\GraphQL\Tools\Schema;

use Anorm\GraphQL\Tools\TypeInfo;

/**
 * Maintains the generated Query and Mutation entries of an existing ApiSchema.php.
 *
 * Only entries whose code is led directly by the marker comment are ever rewritten.
 * Everything else is copied through byte for byte. When the file is not shaped as
 * expected, or the result would not parse, the source is returned untouched.
 */
class SchemaEditor
{
    const GRAPHQL_UTILS = 'Anorm\GraphQL\GraphQLUtils';
    const MANGO_INPUT = 'Anorm\GraphQL\Type\MangoInput';
    const TYPE = 'GraphQL\Type\Definition\Type';

    /** @var string Namespace of the generated Types, no trailing backslash */
    private $typeNamespace;

    public function __construct($typeNamespace)
    {
        $this->typeNamespace = \trim($typeNamespace, '\\');
    }

    /**
     * @param string $source
     * @param TypeInfo[] $infos The entities of this run
     * @param string[] $knownEntities Every entity the models produce, including those
     *                                this run leaves out; their entries are not orphans.
     *                                Required: leaving it out would call every marked
     *                                entry of every other entity an orphan.
     * @return SchemaEditResult
     */
    public function edit($source, array $infos, array $knownEntities)
    {
        $result = new SchemaEditResult();
        $result->source = $source;
        // With no entities there is nothing to write, but there may still be something to
        // say: entries marked as generated whose entity no model produces any more.

        $desired = array('query' => array(), 'mutation' => array());
        foreach ($infos as $info) {
            foreach ($this->fieldNames($info) as $rootKey => $kinds) {
                foreach ($kinds as $kind => $name) {
                    if (isset($desired['query'][$name]) || isset($desired['mutation'][$name])) {
                        $result->messages[] = "skipped: '{$info->entity}' would define '$name', which another entity already defines";
                        continue;
                    }
                    $desired[$rootKey][$name] = array($kind, $info);
                }
            }
        }
        $known = array();
        foreach ($knownEntities as $entity) {
            foreach (array('List', 'Create', 'Delete', 'Update', 'Upsert') as $suffix) {
                $known[\lcfirst($entity) . $suffix] = true;
            }
        }
        foreach ($infos as $info) {
            // For an entity of this run, what it produces now is known exactly.
            foreach (array('List', 'Create', 'Delete', 'Update', 'Upsert') as $suffix) {
                unset($known[$info->fieldPrefix() . $suffix]);
            }
        }

        try {
            $tokens = new Tokens($source);
            $locator = new FieldsArrayLocator();
            $arrays = array();
            foreach (array('query', 'mutation') as $rootKey) {
                $arrays[$rootKey] = $locator->locate($source, $tokens, $rootKey);
                $needed = $rootKey === 'query' || $desired['mutation'];
                if ($arrays[$rootKey] === null && $needed) {
                    throw new SchemaShapeException(
                        "could not find '$rootKey' => new ObjectType([ ... 'fields' => [ ... ] ... ])"
                    );
                }
            }
            $imports = new ImportTable($source, $tokens);
            // Whichever line ending the file mostly uses; one stray CRLF does not make a CRLF file.
            $crlf = \substr_count($source, "\r\n");
            $eol = $crlf > \substr_count($source, "\n") - $crlf ? "\r\n" : "\n";

            $messages = array();
            $splices = array();
            foreach (array('query', 'mutation') as $rootKey) {
                if ($arrays[$rootKey] === null) {
                    continue;
                }
                $interior = $this->editArray($arrays[$rootKey], $desired[$rootKey], $known, $imports, $eol, $messages);
                $array = $arrays[$rootKey];
                $splices[] = array($array->start, $array->end - $array->start, $interior);
            }
            $splices = \array_merge($splices, $imports->splices($eol));
            $edited = $this->apply($source, $splices);
            // Belt and braces: whatever went wrong above, never write a file that does not parse.
            $problem = $this->parseError($edited);
            if ($problem !== null) {
                throw new SchemaShapeException(
                    $this->parseError($source) === null
                        ? 'internal error, please report it: the edit would not have parsed (' . $problem . ')'
                        : 'the file does not parse under PHP ' . PHP_VERSION . ' (' . $problem . ')'
                );
            }
        } catch (SchemaShapeException $e) {
            if (!$infos) {
                // Nothing was going to be written, so there is nothing to refuse or to paste.
                return $result;
            }
            return $this->failure($result, $desired, $e->getMessage());
        }

        $result->source = $edited;
        $result->messages = \array_merge($result->messages, $messages);
        return $result;
    }

    /** @return string|null Why $code does not parse, or null when it does */
    private function parseError($code)
    {
        try {
            $parsed = \token_get_all($code, TOKEN_PARSE);
            unset($parsed);
            return null;
        } catch (\ParseError $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return array<string, array<string, string>> root key => kind ('List', 'Create', 'Delete', 'Update', 'Upsert') => field name
     */
    private function fieldNames(TypeInfo $info)
    {
        $prefix = $info->fieldPrefix();
        if ($info->inputOnly && !$info->readOnly) {
            return array('query' => array(), 'mutation' => array());
        }
        $names = array('query' => array('List' => $prefix . 'List'), 'mutation' => array());
        if (!$info->readOnly && !$info->inputOnly) {
            $kinds = $info->mutations === 'create-update' ? array('Create', 'Delete', 'Update') : array('Delete', 'Upsert');
            foreach ($kinds as $kind) {
                $names['mutation'][$kind] = $prefix . $kind;
            }
        }
        return $names;
    }

    /**
     * The lines of one entry, without indentation and ending in its comma.
     *
     * @param string $kind 'List', 'Create', 'Delete', 'Update' or 'Upsert'
     * @param callable $name Turns a fully qualified class name into the text to write for it
     * @return string[]
     */
    public function entryLines($kind, TypeInfo $info, callable $name)
    {
        $field = $info->fieldPrefix() . $kind;
        $base = $this->typeNamespace . '\\' . $info->entity . '\\' . $info->entity;
        $utils = $name(self::GRAPHQL_UTILS);
        $type = $name($base . 'Type');
        $first = "{$utils}::createListField('$field', \$this->type({$type}::class), 'resolve$kind')";
        if ($kind === 'List') {
            $argument = "->addArgument('query', \$this->type(" . $name(self::MANGO_INPUT) . '::class))';
        } elseif ($kind === 'Delete') {
            $t = $name(self::TYPE);
            $argument = "->addArgument('id', {$t}::nonNull({$t}::listOf({$t}::nonNull({$t}::id()))))";
        } else {
            $t = $name(self::TYPE);
            $input = $name($base . ($kind === 'Upsert' ? '' : $kind) . 'Input');
            $argument = "->addArgument('input', {$t}::nonNull({$t}::listOf({$t}::nonNull(\$this->type({$input}::class)))))";
        }
        return array($first, '    ' . $argument, '    ->build(),');
    }

    /**
     * @param array<string, array{0: string, 1: TypeInfo}> $desired field name => kind and entity
     * @param array<string, bool> $known Field names of entities that exist but are not in this run
     * @param string[] $messages
     * @return string The new inside of the array
     */
    private function editArray(FieldsArray $array, array $desired, array $known, ImportTable $imports, $eol, array &$messages)
    {
        $resolve = array($imports, 'resolve');
        $texts = array();
        $needsComma = array();
        $sortNames = array();
        $indents = array();
        $handWritten = array();
        foreach ($array->entries as $entry) {
            if (!$entry->owned) {
                $handWritten = \array_merge($handWritten, $entry->names);
            }
        }
        \ksort($desired, SORT_STRING);

        $written = array();
        foreach ($array->entries as $entry) {
            $text = $entry->text;
            $name = $entry->primaryName();
            $rewrite = $entry->owned && $name !== null && isset($desired[$name]) && !\in_array($name, $handWritten, true);
            if ($rewrite && isset($written[$name])) {
                $messages[] = "duplicate: '$name' is marked as generated more than once; the later entry was left alone";
                $rewrite = false;
            } elseif ($rewrite) {
                $lines = $this->entryLines($desired[$name][0], $desired[$name][1], $resolve);
                // What followed the comma, a trailing comment perhaps, stays.
                $text = $entry->beforeMarker . $this->render($entry->indent, $lines, $eol, $entry->afterComma);
                $written[$name] = true;
            } elseif ($entry->owned && $name === null) {
                $messages[] = 'orphaned: an entry is marked as generated but its field name cannot be read';
            } elseif ($entry->owned && !isset($desired[$name]) && !isset($known[$name])) {
                $messages[] = "orphaned: '$name' is marked as generated but no model produces it";
            }
            // An entry kept as it was, with no comma after it, needs one if it stops being last.
            $needsComma[] = !$rewrite && $entry->missingCommaAt !== null ? $entry->missingCommaAt : null;
            $texts[] = $text;
            $sortNames[] = $name;
            $indents[] = $entry->indent;
        }

        $defaultIndent = $array->entries ? $array->entries[0]->indent : $array->bracketIndent . '    ';
        foreach ($desired as $name => $spec) {
            if (isset($written[$name])) {
                continue;
            }
            if (\in_array($name, $handWritten, true)) {
                $messages[] = "collision: '$name' already exists and is not marked as generated; left alone";
                continue;
            }
            $at = \count($texts);
            foreach ($sortNames as $i => $existing) {
                if ($existing !== null && \strcmp($existing, $name) > 0) {
                    $at = $i;
                    break;
                }
            }
            $indent = $defaultIndent;
            if (isset($indents[$at])) {
                $indent = $indents[$at];
            } elseif ($indents) {
                $indent = $indents[\count($indents) - 1];
            }
            $lines = $this->entryLines($spec[0], $spec[1], $resolve);
            \array_splice($texts, $at, 0, array($this->render($indent, $lines, $eol, $eol)));
            \array_splice($needsComma, $at, 0, array(null));
            \array_splice($sortNames, $at, 0, array($name));
            \array_splice($indents, $at, 0, array($indent));
        }

        foreach ($needsComma as $i => $at) {
            if ($at !== null && $i < \count($texts) - 1) {
                $texts[$i] = \substr($texts[$i], 0, $at) . ',' . \substr($texts[$i], $at);
            }
        }

        if ($array->emptyOnOneLine) {
            return $texts ? $eol . \implode('', $texts) . $array->bracketIndent : '';
        }
        return $array->head . \implode('', $texts) . $array->tail;
    }

    /**
     * @param string[] $lines
     * @param string $afterComma What follows the entry's comma: normally just the line ending
     */
    private function render($indent, array $lines, $eol, $afterComma)
    {
        return $indent . FieldsArrayLocator::MARKER . $eol . $indent . \implode($eol . $indent, $lines) . $afterComma;
    }

    /**
     * @param array<int, array{0: int, 1: int, 2: string}> $splices Offsets are into $source as given
     */
    private function apply($source, array $splices)
    {
        \usort($splices, function ($a, $b) {
            return $b[0] <=> $a[0];
        });
        foreach ($splices as $splice) {
            $source = \substr($source, 0, $splice[0]) . $splice[2] . \substr($source, $splice[0] + $splice[1]);
        }
        return $source;
    }

    /**
     * @param array<string, array<string, array{0: string, 1: TypeInfo}>> $desired
     */
    private function failure(SchemaEditResult $result, array $desired, $why)
    {
        $result->failed = true;
        $result->messages[] = 'ApiSchema not changed: ' . $why;
        $fullyQualified = function ($fqcn) {
            return '\\' . $fqcn;
        };
        foreach ($desired as $rootKey => $entries) {
            foreach ($entries as $spec) {
                $result->paste[$rootKey][] = FieldsArrayLocator::MARKER . "\n"
                    . \implode("\n", $this->entryLines($spec[0], $spec[1], $fullyQualified));
            }
        }
        return $result;
    }
}
