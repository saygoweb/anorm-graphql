<?php
namespace Anorm\GraphQL\Tools\Schema;

/**
 * Finds the `'fields' => [ ... ]` array under a root key of an ApiSchema and splits
 * it into entries, using PHP's own tokenizer so that brackets, strings and comments
 * are never misread. Only token kinds that are identical on PHP 7.4 and 8.x are
 * relied on.
 */
class FieldsArrayLocator
{
    const MARKER = '// anorm-graphql';

    /**
     * @param string $source PHP source of the schema file
     * @param string $rootKey 'query' or 'mutation'
     * @return FieldsArray|null null when the expected structure is not there
     */
    public function locate($source, $rootKey)
    {
        $tokens = $this->tokens($source);
        $count = \count($tokens);
        $rootAt = $this->findKey($tokens, 0, $count, $rootKey);
        if ($rootAt === null) {
            return null;
        }
        $fieldsAt = $this->findKey($tokens, $rootAt + 1, $count, 'fields');
        if ($fieldsAt === null) {
            return null;
        }
        $open = $this->nextCode($tokens, $this->nextCode($tokens, $fieldsAt) ?? $count);
        if ($open === null || $tokens[$open]['text'] !== '[') {
            return null;
        }
        return $this->split($source, $tokens, $open);
    }

    /**
     * @return array<int, array{id: int|null, text: string, offset: int}>
     */
    private function tokens($source)
    {
        $result = array();
        $offset = 0;
        foreach (\token_get_all($source) as $token) {
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? $token[1] : $token;
            $result[] = array('id' => $id, 'text' => $text, 'offset' => $offset);
            $offset += \strlen($text);
        }
        return $result;
    }

    /** Index of the string literal $key that is followed by `=>`, or null. */
    private function findKey(array $tokens, $from, $count, $key)
    {
        for ($i = $from; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (\substr($tokens[$i]['text'], 1, -1) !== $key) {
                continue;
            }
            $next = $this->nextCode($tokens, $i);
            if ($next !== null && $tokens[$next]['id'] === T_DOUBLE_ARROW) {
                return $i;
            }
        }
        return null;
    }

    /** Index of the next token after $i that is not whitespace or a comment. */
    private function nextCode(array $tokens, $i)
    {
        $count = \count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (!$this->isTrivia($tokens[$j])) {
                return $j;
            }
        }
        return null;
    }

    private function isTrivia(array $token)
    {
        return $token['id'] === T_WHITESPACE || $token['id'] === T_COMMENT || $token['id'] === T_DOC_COMMENT;
    }

    private function split($source, array $tokens, $open)
    {
        $array = new FieldsArray();
        $array->start = $tokens[$open]['offset'] + 1;
        $array->bracketIndent = $this->lineIndent($source, $tokens[$open]['offset']);

        $depth = 0;
        $segment = array();
        $count = \count($tokens);
        for ($i = $open + 1; $i < $count; $i++) {
            $text = $tokens[$i]['text'];
            $id = $tokens[$i]['id'];
            if ($id === null && $text === ']' && $depth === 0) {
                $array->end = $tokens[$i]['offset'];
                $this->finish($array, $segment);
                return $array;
            }
            $segment[] = $tokens[$i];
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($id === null && ($text === '[' || $text === '(' || $text === '{')) {
                $depth++;
            } elseif ($id === null && ($text === ']' || $text === ')' || $text === '}')) {
                $depth--;
            } elseif ($id === null && $text === ',' && $depth === 0) {
                $array->entries[] = $this->entry($segment, true);
                $segment = array();
            }
        }
        return null;
    }

    /** The last segment is an entry without a comma, or just the tail before `]`. */
    private function finish(FieldsArray $array, array $segment)
    {
        $hasCode = false;
        foreach ($segment as $token) {
            if (!$this->isTrivia($token)) {
                $hasCode = true;
                break;
            }
        }
        if (!$hasCode) {
            $array->tail = $this->text($segment);
            return;
        }
        // Trailing whitespace belongs to the tail, not to the entry.
        $tail = array();
        while ($segment && $segment[\count($segment) - 1]['id'] === T_WHITESPACE) {
            \array_unshift($tail, \array_pop($segment));
        }
        $array->entries[] = $this->entry($segment, false);
        $array->tail = $this->text($tail);
    }

    private function entry(array $segment, $hasComma)
    {
        $entry = new FieldsEntry();
        $entry->text = $this->text($segment);
        $entry->hasComma = $hasComma;

        $lead = '';
        $markerLead = null;
        foreach ($segment as $token) {
            if (!$this->isTrivia($token)) {
                break;
            }
            if ($token['id'] === T_COMMENT && \trim($token['text']) === self::MARKER) {
                $markerLead = $lead;
            }
            $lead .= $token['text'];
        }
        $entry->owned = $markerLead !== null;
        $entry->lead = $entry->owned ? $markerLead : $lead;

        $newline = \strrpos($lead, "\n");
        $entry->indent = $newline === false ? '' : \substr($lead, $newline + 1);

        foreach ($segment as $token) {
            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
                $entry->name = \substr($token['text'], 1, -1);
                break;
            }
        }
        return $entry;
    }

    private function text(array $segment)
    {
        $text = '';
        foreach ($segment as $token) {
            $text .= $token['text'];
        }
        return $text;
    }

    private function lineIndent($source, $offset)
    {
        $lineStart = \strrpos(\substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        \preg_match('/^[ \t]*/', \substr($source, $lineStart), $m);
        return $m[0];
    }
}
