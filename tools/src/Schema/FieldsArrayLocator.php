<?php
namespace Anorm\GraphQL\Tools\Schema;

/**
 * Finds the fields array of the Query or Mutation type in an ApiSchema and splits it
 * into entries.
 *
 * It is deliberately narrow. The array must be reached as
 * `'query' => new ObjectType([ ... 'fields' => [ ... ] ... ])`, with `'fields'` a direct
 * key of the ObjectType's config, and it must be written one entry per line. Anything
 * else is refused with a SchemaShapeException, because a wrong guess here edits the
 * wrong part of somebody's hand-written file.
 */
class FieldsArrayLocator
{
    const MARKER = '// anorm-graphql';

    /**
     * @param string $source
     * @param Tokens $tokens Of the same source
     * @param string $rootKey 'query' or 'mutation'
     * @return FieldsArray|null null when the schema has no such root key in the expected form
     * @throws SchemaShapeException when it is there but not in a shape that is safe to edit
     */
    public function locate($source, Tokens $tokens, $rootKey)
    {
        $configs = $this->objectTypeConfigs($tokens, $rootKey);
        if (!$configs) {
            return null;
        }
        if (\count($configs) > 1) {
            throw new SchemaShapeException("'$rootKey' => new ObjectType([...]) appears more than once");
        }
        $config = $configs[0];

        $opens = array();
        for ($i = $config + 1; $i < $tokens->closes[$config]; $i++) {
            if ($tokens->parent[$i] !== $config || !$tokens->isString($i, 'fields')) {
                continue;
            }
            $arrow = $tokens->nextCode($i);
            if (!$tokens->isId($arrow, T_DOUBLE_ARROW)) {
                continue;
            }
            $open = $tokens->nextCode($arrow);
            if (!$tokens->is($open, '[')) {
                throw new SchemaShapeException("'fields' under '$rootKey' is not a literal [ ... ] array");
            }
            $opens[] = $open;
        }
        if (\count($opens) !== 1) {
            throw new SchemaShapeException("expected exactly one 'fields' key in the '$rootKey' ObjectType");
        }
        return $this->split($source, $tokens, $opens[0], $rootKey);
    }

    /**
     * Where `'<rootKey>' => new ObjectType([` occurs.
     *
     * @return int[] Index of the `[` that opens each config array
     */
    private function objectTypeConfigs(Tokens $tokens, $rootKey)
    {
        $found = array();
        foreach ($tokens->list as $i => $token) {
            if (!$tokens->isString($i, $rootKey)) {
                continue;
            }
            $arrow = $tokens->nextCode($i);
            $new = $tokens->isId($arrow, T_DOUBLE_ARROW) ? $tokens->nextCode($arrow) : null;
            if (!$tokens->isId($new, T_NEW)) {
                continue;
            }
            $name = $tokens->readName($tokens->nextCode($new));
            if ($name === null || !\preg_match('/(^|\\\\)ObjectType$/', $name[0])) {
                continue;
            }
            $paren = $tokens->isTrivia($name[1]) ? $tokens->nextCode($name[1]) : $name[1];
            $bracket = $tokens->is($paren, '(') ? $tokens->nextCode($paren) : null;
            if ($tokens->is($bracket, '[')) {
                $found[] = $bracket;
            }
        }
        return $found;
    }

    private function split($source, Tokens $tokens, $open, $rootKey)
    {
        $close = $tokens->closes[$open];
        $array = new FieldsArray();
        $array->start = $tokens->list[$open]['offset'] + 1;
        $array->end = $tokens->list[$close]['offset'];
        $array->bracketIndent = $this->indentOfLine($source, $tokens->list[$open]['offset']);

        $interior = \substr($source, $array->start, $array->end - $array->start);
        if (\strpos($interior, "\n") === false) {
            if (\trim($interior) !== '') {
                throw new SchemaShapeException("the '$rootKey' fields array must be written one entry per line");
            }
            $array->emptyOnOneLine = true;
            return $array;
        }

        $cursor = $this->restOfLine($tokens, $open + 1, $close, $array->start);
        $this->requireLineEnd($source, $array->start, $cursor, $rootKey);
        $array->head = \substr($source, $array->start, $cursor[1] - $array->start);

        $i = $cursor[0];
        $from = $cursor[1];
        $firstCode = null;
        $lastCode = null;
        for (; $i < $close; $i++) {
            if (!$tokens->isTrivia($i)) {
                $firstCode = $firstCode === null ? $i : $firstCode;
                $lastCode = $i;
            }
            if ($tokens->is($i, ',') && $tokens->parent[$i] === $open) {
                $commaEnd = $tokens->list[$i]['offset'] + 1;
                $cursor = $this->restOfLine($tokens, $i + 1, $close, $commaEnd);
                $this->requireLineEnd($source, $commaEnd, $cursor, $rootKey);
                $entry = $this->entry($source, $tokens, $from, $cursor[1], $firstCode);
                $entry->afterComma = \substr($source, $commaEnd, $cursor[1] - $commaEnd);
                $array->entries[] = $entry;
                $from = $cursor[1];
                $i = $cursor[0] - 1;
                $firstCode = null;
                $lastCode = null;
            }
        }
        if ($firstCode !== null) {
            // A last entry with no comma after it.
            $codeEnd = $tokens->list[$lastCode]['offset'] + \strlen($tokens->list[$lastCode]['text']);
            $cursor = $this->restOfLine($tokens, $lastCode + 1, $close, $codeEnd);
            $this->requireLineEnd($source, $codeEnd, $cursor, $rootKey);
            $entry = $this->entry($source, $tokens, $from, $cursor[1], $firstCode);
            $entry->missingCommaAt = $codeEnd - $from;
            $entry->afterComma = \substr($source, $codeEnd, $cursor[1] - $codeEnd);
            $array->entries[] = $entry;
            $from = $cursor[1];
        }
        $array->tail = \substr($source, $from, $array->end - $from);
        return $array;
    }

    /**
     * From just after a comma (or the opening bracket), take what else is on that line:
     * whitespace, a trailing comment, the line ending.
     *
     * @return array{0: int, 1: int} Index of the first token not taken, and the offset reached
     */
    private function restOfLine(Tokens $tokens, $i, $close, $offset)
    {
        for (; $i < $close; $i++) {
            $token = $tokens->list[$i];
            $text = $token['text'];
            if ($token['id'] === T_WHITESPACE) {
                $newline = \strpos($text, "\n");
                if ($newline === false) {
                    $offset = $token['offset'] + \strlen($text);
                    continue;
                }
                $offset = $token['offset'] + $newline + 1;
                // The rest of this whitespace, the next line's indentation, stays where it is.
                return array($newline + 1 === \strlen($text) ? $i + 1 : $i, $offset);
            }
            $oneLineComment = $token['id'] === T_COMMENT && \strpos(\rtrim($text, "\r\n"), "\n") === false;
            if (!$oneLineComment) {
                break;
            }
            $offset = $token['offset'] + \strlen($text);
            if (\substr($text, -1) === "\n") {
                return array($i + 1, $offset);
            }
        }
        return array($i, $offset);
    }

    /**
     * @param array{0: int, 1: int} $cursor
     */
    private function requireLineEnd($source, $from, array $cursor, $rootKey)
    {
        if ($cursor[1] > $from && $source[$cursor[1] - 1] === "\n") {
            return;
        }
        throw new SchemaShapeException(
            "the '$rootKey' fields array must be written one entry per line, with its closing bracket on a line of its own"
        );
    }

    private function entry($source, Tokens $tokens, $from, $to, $firstCode)
    {
        $entry = new FieldsEntry();
        $entry->text = \substr($source, $from, $to - $from);
        $codeOffset = $tokens->list[$firstCode]['offset'];
        $entry->indent = $this->indentOfLine($source, $codeOffset);

        // Owned only if the marker is the last thing before the code, whitespace aside.
        for ($i = $firstCode - 1; $i >= 0 && $tokens->list[$i]['offset'] >= $from; $i--) {
            if ($tokens->list[$i]['id'] === T_WHITESPACE) {
                continue;
            }
            $isMarker = $tokens->list[$i]['id'] === T_COMMENT && \trim($tokens->list[$i]['text']) === self::MARKER;
            $lineStart = $this->lineStart($source, $tokens->list[$i]['offset']);
            if ($isMarker && $lineStart >= $from && \trim(\substr($source, $lineStart, $tokens->list[$i]['offset'] - $lineStart)) === '') {
                $entry->owned = true;
                $entry->beforeMarker = \substr($source, $from, $lineStart - $from);
            }
            break;
        }
        $entry->names = $this->names($tokens, $firstCode, $from + \strlen($entry->text));
        return $entry;
    }

    /**
     * The field name an entry defines: the first string literal, as in
     * `createField('name', ...)` and `'name' => [...]`, or the value of the `'name'` key
     * when the entry is itself an array.
     *
     * @return string[]
     */
    private function names(Tokens $tokens, $firstCode, $endOffset)
    {
        if ($tokens->is($firstCode, '[')) {
            for ($i = $firstCode + 1; $i < $tokens->closes[$firstCode]; $i++) {
                if ($tokens->parent[$i] !== $firstCode || !$tokens->isString($i, 'name')) {
                    continue;
                }
                $arrow = $tokens->nextCode($i);
                $value = $tokens->isId($arrow, T_DOUBLE_ARROW) ? $tokens->nextCode($arrow) : null;
                return $tokens->isString($value) ? array($tokens->stringValue($value)) : array();
            }
            return array();
        }
        for ($i = $firstCode, $n = \count($tokens->list); $i < $n && $tokens->list[$i]['offset'] < $endOffset; $i++) {
            if ($tokens->isString($i)) {
                return array($tokens->stringValue($i));
            }
        }
        return array();
    }

    private function lineStart($source, $offset)
    {
        $newline = $offset === 0 ? false : \strrpos($source, "\n", $offset - \strlen($source) - 1);
        return $newline === false ? 0 : $newline + 1;
    }

    /** @return string The whitespace a line starts with, when that is all that precedes $offset on it */
    private function indentOfLine($source, $offset)
    {
        $start = $this->lineStart($source, $offset);
        \preg_match('/^[ \t]*/', \substr($source, $start, $offset - $start), $m);
        return $m[0];
    }
}
