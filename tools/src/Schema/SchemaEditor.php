<?php
namespace Anorm\GraphQL\Tools\Schema;

use Anorm\GraphQL\Tools\TypeInfo;

/**
 * Maintains the generated Query and Mutation entries of an existing ApiSchema.php.
 *
 * Only entries led by the marker comment are ever rewritten. Everything else is
 * copied through byte for byte, and when the file is not shaped as expected the
 * source is returned untouched.
 */
class SchemaEditor
{
    const RUNTIME_IMPORTS = array(
        'Anorm\GraphQL\GraphQLUtils',
        'Anorm\GraphQL\Type\MangoInput',
        'GraphQL\Type\Definition\Type',
    );

    /** @var FieldsArrayLocator */
    private $locator;

    /** @var string Namespace of the generated Types, no trailing backslash */
    private $typeNamespace;

    /** @var array<string, bool> Field names that belong to an entity that still exists */
    private $known = array();

    public function __construct($typeNamespace)
    {
        $this->locator = new FieldsArrayLocator();
        $this->typeNamespace = \trim($typeNamespace, '\\');
    }

    /**
     * @param string $source
     * @param TypeInfo[] $infos The entities of this run
     * @param string[] $knownEntities Every entity the models produce, including those
     *                                this run leaves out; their entries are not orphans
     * @return SchemaEditResult
     */
    public function edit($source, array $infos, array $knownEntities = array())
    {
        $result = new SchemaEditResult();
        $result->source = $source;
        if (!$infos) {
            return $result;
        }

        $this->known = array();
        foreach ($knownEntities as $entity) {
            foreach (array('List', 'Delete', 'Upsert') as $suffix) {
                $this->known[\lcfirst($entity) . $suffix] = true;
            }
        }

        $desired = array('query' => array(), 'mutation' => array());
        foreach ($infos as $info) {
            foreach ($this->entriesFor($info) as $rootKey => $entries) {
                $desired[$rootKey] += $entries;
            }
        }

        foreach (array('query', 'mutation') as $rootKey) {
            if ($this->locator->locate($source, $rootKey) === null) {
                $result->failed = true;
                $result->messages[] = "ApiSchema not changed: could not find a literal 'fields' => [ ... ] array under '$rootKey'";
            }
        }
        if ($result->failed) {
            foreach ($desired as $entries) {
                foreach ($entries as $lines) {
                    $result->paste[] = \implode("\n", $lines);
                }
            }
            return $result;
        }

        $edited = $source;
        foreach (array('query', 'mutation') as $rootKey) {
            // Located afresh each time: the first edit moves every later offset.
            $array = $this->locator->locate($edited, $rootKey);
            $edited = $this->editArray($edited, $array, $desired[$rootKey], $result);
        }
        $result->source = $this->addImports($edited, $this->importsFor($infos));
        return $result;
    }

    /**
     * @return array<string, array<string, string[]>> root key => field name => lines of code
     */
    public function entriesFor(TypeInfo $info)
    {
        $prefix = $info->fieldPrefix();
        $type = $info->entity . 'Type';
        $input = $info->entity . 'Input';
        $entries = array('query' => array(), 'mutation' => array());
        $entries['query'][$prefix . 'List'] = array(
            "GraphQLUtils::createListField('{$prefix}List', \$this->type({$type}::class), 'resolveList')",
            "    ->addArgument('query', \$this->type(MangoInput::class))",
            "    ->build(),",
        );
        if ($info->readOnly) {
            return $entries;
        }
        $entries['mutation'][$prefix . 'Delete'] = array(
            "GraphQLUtils::createListField('{$prefix}Delete', \$this->type({$type}::class), 'resolveDelete')",
            "    ->addArgument('id', Type::nonNull(Type::listOf(Type::nonNull(Type::id()))))",
            "    ->build(),",
        );
        $entries['mutation'][$prefix . 'Upsert'] = array(
            "GraphQLUtils::createListField('{$prefix}Upsert', \$this->type({$type}::class), 'resolveUpsert')",
            "    ->addArgument('input', Type::nonNull(Type::listOf(Type::nonNull(\$this->type({$input}::class)))))",
            "    ->build(),",
        );
        return $entries;
    }

    /**
     * @param TypeInfo[] $infos
     * @return string[]
     */
    private function importsFor(array $infos)
    {
        $imports = self::RUNTIME_IMPORTS;
        foreach ($infos as $info) {
            $base = $this->typeNamespace . '\\' . $info->entity . '\\' . $info->entity;
            $imports[] = $base . 'Type';
            if (!$info->readOnly) {
                $imports[] = $base . 'Input';
            }
        }
        return $imports;
    }

    /**
     * @param array<string, string[]> $desired field name => lines of code
     */
    private function editArray($source, FieldsArray $array, array $desired, SchemaEditResult $result)
    {
        $segments = array();
        $names = array();
        $commaless = null;
        foreach ($array->entries as $entry) {
            $text = $entry->text;
            if ($entry->owned && $entry->name !== null && isset($desired[$entry->name])) {
                $text = $this->render($entry->lead, $entry->indent, $desired[$entry->name]);
            } elseif ($entry->owned && !isset($this->known[(string) $entry->name])) {
                $result->messages[] = "orphaned: '{$entry->name}' is marked as generated but no model produces it";
            } elseif ($entry->name !== null && isset($desired[$entry->name])) {
                $result->messages[] = "collision: '{$entry->name}' already exists and is not marked as generated; left alone";
            }
            if (!$entry->hasComma) {
                $commaless = $entry->name;
            }
            $segments[] = $text;
            $names[] = $entry->name;
        }

        $existing = \array_flip(\array_filter($names, 'is_string'));
        $missing = \array_diff_key($desired, $existing);
        \ksort($missing, SORT_STRING);

        $defaultIndent = $array->entries ? $array->entries[0]->indent : $array->bracketIndent . '    ';
        foreach ($missing as $name => $lines) {
            $at = \count($segments);
            foreach ($names as $i => $existingName) {
                if ($existingName !== null && \strcmp($existingName, $name) > 0) {
                    $at = $i;
                    break;
                }
            }
            $indent = $defaultIndent;
            if (isset($array->entries[$at])) {
                $indent = $array->entries[$at]->indent;
            } elseif ($array->entries) {
                $indent = $array->entries[\count($array->entries) - 1]->indent;
            }
            \array_splice($segments, $at, 0, array($this->render("\n", $indent, $lines)));
            \array_splice($names, $at, 0, array($name));
            // Keep $array->entries aligned with $segments for the indent lookups above.
            $placeholder = new FieldsEntry();
            $placeholder->indent = $indent;
            \array_splice($array->entries, $at, 0, array($placeholder));
        }

        // A hand-written last entry may have had no comma; it needs one if it is no longer last.
        $last = \count($segments) - 1;
        foreach ($names as $i => $existingName) {
            if ($commaless !== null && $existingName === $commaless && $i < $last && \substr(\rtrim($segments[$i]), -1) !== ',') {
                $segments[$i] .= ',';
            }
        }

        $tail = $array->tail;
        if ($segments && \strpos($tail, "\n") === false) {
            $tail = "\n" . $array->bracketIndent . $tail;
        }
        return \substr($source, 0, $array->start) . \implode('', $segments) . $tail . \substr($source, $array->end);
    }

    /**
     * @param string $lead Whitespace before the marker comment, ending at the marker's indentation
     * @param string[] $lines
     */
    private function render($lead, $indent, array $lines)
    {
        if (\strpos($lead, "\n") === false) {
            $lead = "\n" . $indent;
        } elseif (\substr($lead, -1) === "\n") {
            $lead .= $indent;
        }
        return $lead . FieldsArrayLocator::MARKER . "\n" . $indent . \implode("\n" . $indent, $lines);
    }

    /**
     * Add each missing `use` line in alphabetical position. Nothing is ever removed.
     * Line based on purpose: PHP 7.4 and 8.x tokenize qualified names differently.
     *
     * @param string[] $imports Fully qualified class names
     */
    private function addImports($source, array $imports)
    {
        \sort($imports, SORT_STRING);
        foreach ($imports as $import) {
            $lines = \explode("\n", $source);
            $uses = array();
            $namespaceLine = null;
            foreach ($lines as $i => $line) {
                if (\preg_match('/^\s*(abstract\s+|final\s+)?class\s/', $line)) {
                    break;
                }
                if (\preg_match('/^namespace\s/', $line)) {
                    $namespaceLine = $i;
                }
                if (\preg_match('/^use\s+\\\\?([\w\\\\]+)(\s+as\s+\w+)?\s*;/', $line, $m)) {
                    $uses[$i] = $m[1];
                }
            }
            if (\in_array($import, $uses, true)) {
                continue;
            }
            $newLine = 'use ' . $import . ';';
            if (!$uses) {
                $at = $namespaceLine === null ? 1 : $namespaceLine + 1;
                \array_splice($lines, $at, 0, array('', $newLine));
            } else {
                $at = \max(\array_keys($uses)) + 1;
                foreach ($uses as $i => $existing) {
                    if (\strcmp($existing, $import) > 0) {
                        $at = $i;
                        break;
                    }
                }
                \array_splice($lines, $at, 0, array($newLine));
            }
            $source = \implode("\n", $lines);
        }
        return $source;
    }
}
