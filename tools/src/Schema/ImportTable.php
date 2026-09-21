<?php
namespace Anorm\GraphQL\Tools\Schema;

/**
 * The names a file's `use` statements bind, and a safe way to refer to one more class.
 *
 * PHP cares about the short name an import binds, not the class it points at: two
 * imports ending in `Type` are a fatal error however different their namespaces. So a
 * class is imported only when its short name is free. When it is taken, the generated
 * code names the class in full instead, which always works.
 *
 * "Taken" includes a name the file merely uses. An unqualified `GraphQLUtils` with no
 * import means a class of that name in the file's own namespace; importing another
 * `GraphQLUtils` would compile, and silently repoint every one of those references.
 */
class ImportTable
{
    /** @var array<string, array{fqcn: string, name: string}> Lower-case bound name => what it binds */
    private $bound = array();
    /** @var array<int, array{fqcn: string, start: int, end: int, simple: bool}> Top-level use statements, in order */
    private $statements = array();
    /** @var array<string, bool> Lower-case names the file uses without qualifying or importing them */
    private $referenced = array();
    /** @var string[] Classes to import, as decided by resolve() */
    private $added = array();
    /** @var int|null Where a first import would go; null when imports cannot be added safely */
    private $fallbackOffset = null;
    /** @var bool */
    private $fallbackNeedsBlankLine = false;
    /** @var bool true when the imports could not be read with confidence: then nothing is imported or trusted */
    private $unsure = false;
    /** @var string */
    private $source;

    public function __construct($source, Tokens $tokens)
    {
        $this->source = $source;
        $this->parse($tokens);
    }

    /**
     * The text to write for a class: its short name when that is, or can be made, an
     * import of exactly this class, and otherwise the fully qualified name.
     *
     * @param string $fqcn No leading backslash
     * @return string
     */
    public function resolve($fqcn)
    {
        if ($this->unsure) {
            return '\\' . $fqcn;
        }
        foreach ($this->bound as $binding) {
            if (\strcasecmp($binding['fqcn'], $fqcn) === 0) {
                return $binding['name'];
            }
        }
        $parts = \explode('\\', $fqcn);
        $short = \end($parts);
        $key = \strtolower($short);
        $taken = isset($this->bound[$key]) || isset($this->referenced[$key]);
        if ($taken || ($this->fallbackOffset === null && !$this->statements)) {
            return '\\' . $fqcn;
        }
        $this->bound[$key] = array('fqcn' => $fqcn, 'name' => $short);
        $this->added[] = $fqcn;
        return $short;
    }

    /**
     * @param string $eol
     * @return array<int, array{0: int, 1: int, 2: string}> Splices: offset, length, replacement
     */
    public function splices($eol)
    {
        if (!$this->added) {
            return array();
        }
        $added = $this->added;
        \sort($added, SORT_STRING);

        if (!$this->statements) {
            $block = ($this->fallbackNeedsBlankLine ? $eol : '');
            foreach ($added as $fqcn) {
                $block .= 'use ' . $fqcn . ';' . $eol;
            }
            return array(array($this->fallbackOffset, 0, $block));
        }

        $allSimple = true;
        foreach ($this->statements as $statement) {
            $allSimple = $allSimple && $statement['simple'];
        }
        $last = $this->statements[\count($this->statements) - 1];
        $byOffset = array();
        foreach ($added as $fqcn) {
            $at = $last['end'];
            if ($allSimple) {
                foreach ($this->statements as $statement) {
                    if (\strcmp($statement['fqcn'], $fqcn) > 0) {
                        $at = $statement['start'];
                        break;
                    }
                }
            }
            $byOffset[$at] = (isset($byOffset[$at]) ? $byOffset[$at] : '') . 'use ' . $fqcn . ';' . $eol;
        }
        $splices = array();
        foreach ($byOffset as $offset => $text) {
            $splices[] = array($offset, 0, $text);
        }
        return $splices;
    }

    private function parse(Tokens $tokens)
    {
        $namespace = '';
        $namespaces = 0;
        $bracedNamespace = false;
        $afterOpenTag = null;
        $afterDeclare = null;
        $afterNamespace = null;

        $inUse = false;
        foreach ($tokens->list as $i => $token) {
            if ($tokens->depth[$i] === 0) {
                // The names inside a top-level `use` are imports, handled below, not references.
                $inUse = $token['id'] === T_USE || ($inUse && !$tokens->is($i, ';'));
            }
            if ($token['id'] === T_OPEN_TAG && $afterOpenTag === null) {
                $afterOpenTag = $this->endOfLine($token['offset'] + \strlen($token['text']) - 1);
            }
            if ($token['id'] === T_STRING && !$inUse && $this->isClassReference($tokens, $i)) {
                // On 7.4 this is also how the first part of `Type\Action\ActionType` is seen.
                $this->referenced[\strtolower($token['text'])] = true;
            } elseif (!$inUse && $tokens->isQualifiedNameToken($i)) {
                // On 8.x that name is one token. PHP resolves its first part through the
                // imports just as it does an unqualified name, so that part is in use too.
                $parts = \explode('\\', $token['text']);
                if (\strtolower($parts[0]) !== 'namespace') {
                    $this->referenced[\strtolower($parts[0])] = true;
                }
            }
            if ($tokens->depth[$i] !== 0) {
                // A class's own name is bound too, wherever it is declared.
                $this->noteDeclaredClass($tokens, $i, $namespace);
                continue;
            }
            if ($token['id'] === T_DECLARE) {
                $end = $this->statementEnd($tokens, $i);
                $afterDeclare = $end === null ? $afterDeclare : $this->endOfLine($tokens->list[$end]['offset']);
            } elseif ($token['id'] === T_NAMESPACE) {
                $namespaces++;
                $name = $tokens->readName($tokens->nextCode($i));
                $namespace = $name === null ? '' : \trim($name[0], '\\');
                $end = $this->statementEnd($tokens, $i);
                if ($end === null) {
                    $bracedNamespace = true;
                } else {
                    $afterNamespace = $this->endOfLine($tokens->list[$end]['offset']);
                }
            } elseif ($token['id'] === T_USE) {
                $this->parseUse($tokens, $i);
            }
            $this->noteDeclaredClass($tokens, $i, $namespace);
        }

        if ($bracedNamespace || $namespaces > 1) {
            // `namespace X { ... }`, or several namespaces in one file: which imports apply
            // to the schema class is not worth guessing at.
            $this->unsure = true;
        }
        if ($this->unsure) {
            $this->statements = array();
            $this->fallbackOffset = null;
            return;
        }
        if ($afterNamespace !== null) {
            $this->fallbackOffset = $afterNamespace;
            $this->fallbackNeedsBlankLine = true;
        } elseif ($afterDeclare !== null) {
            $this->fallbackOffset = $afterDeclare;
            $this->fallbackNeedsBlankLine = true;
        } else {
            $this->fallbackOffset = $afterOpenTag;
        }
    }

    /**
     * Whether the T_STRING at $i could name a class, as opposed to a method, a property,
     * a constant or a function being declared, or a later part of a qualified name.
     */
    private function isClassReference(Tokens $tokens, $i)
    {
        $before = $i - 1;
        while ($before >= 0 && $tokens->isTrivia($before)) {
            $before--;
        }
        if ($before < 0) {
            return true;
        }
        $notClasses = array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NS_SEPARATOR);
        if (\defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $notClasses[] = \constant('T_NULLSAFE_OBJECT_OPERATOR');
        }
        return !\in_array($tokens->list[$before]['id'], $notClasses, true);
    }

    private function noteDeclaredClass(Tokens $tokens, $i, $namespace)
    {
        $id = $tokens->list[$i]['id'];
        if ($id !== T_CLASS && $id !== T_INTERFACE && $id !== T_TRAIT) {
            return;
        }
        $name = $tokens->nextCode($i);
        if ($tokens->isId($name, T_STRING)) {
            $short = $tokens->list[$name]['text'];
            $this->bound[\strtolower($short)] = array(
                'fqcn' => \ltrim($namespace . '\\' . $short, '\\'),
                'name' => $short,
            );
        }
    }

    private function parseUse(Tokens $tokens, $use)
    {
        $end = $this->statementEnd($tokens, $use);
        if ($end === null) {
            return;
        }
        $i = $tokens->nextCode($use);
        if ($tokens->isId($i, T_FUNCTION) || $tokens->isId($i, T_CONST)) {
            return;
        }
        $classes = array();
        $grouped = false;
        while ($i !== null && $i < $end) {
            $name = $tokens->readName($i);
            if ($name === null) {
                break;
            }
            $prefix = \trim($name[0], '\\');
            $i = $tokens->isTrivia($name[1]) ? $tokens->nextCode($name[1]) : $name[1];
            if ($tokens->is($i, '{')) {
                $grouped = true;
                $classes = \array_merge($classes, $this->parseGroup($tokens, $i, $prefix));
                $i = $tokens->nextCode($tokens->closes[$i]);
            } else {
                $alias = null;
                if ($tokens->isId($i, T_AS)) {
                    $aliasAt = $tokens->nextCode($i);
                    $alias = $tokens->list[$aliasAt]['text'];
                    $i = $tokens->nextCode($aliasAt);
                }
                $classes[] = array($prefix, $alias);
            }
            if (!$tokens->is($i, ',')) {
                break;
            }
            $i = $tokens->nextCode($i);
        }
        if ($i !== $end) {
            // Something in this statement was not understood, so what it binds is unknown.
            $this->unsure = true;
        }
        foreach ($classes as $class) {
            $parts = \explode('\\', $class[0]);
            $name = $class[1] === null ? \end($parts) : $class[1];
            $this->bound[\strtolower($name)] = array('fqcn' => $class[0], 'name' => $name);
        }

        $start = $tokens->list[$use]['offset'];
        $lineStart = $start === 0 ? false : \strrpos($this->source, "\n", $start - \strlen($this->source) - 1);
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $statementEnd = $this->endOfLine($tokens->list[$end]['offset']);
        $text = \substr($this->source, $lineStart, $statementEnd - $lineStart);
        $this->statements[] = array(
            'fqcn' => \count($classes) === 1 ? $classes[0][0] : '',
            'start' => $lineStart,
            'end' => $statementEnd,
            'simple' => !$grouped && \count($classes) === 1 && $lineStart === $start
                && \substr_count(\rtrim($text, "\r\n"), "\n") === 0,
        );
    }

    /**
     * @return array<int, array{0: string, 1: string|null}> Class and alias pairs
     */
    private function parseGroup(Tokens $tokens, $open, $prefix)
    {
        $classes = array();
        $i = $tokens->nextCode($open);
        while ($i !== null && $i < $tokens->closes[$open]) {
            if ($tokens->isId($i, T_FUNCTION) || $tokens->isId($i, T_CONST)) {
                // A function or constant import binds no class name; skip to the next item.
                while ($i < $tokens->closes[$open] && !$tokens->is($i, ',')) {
                    $i++;
                }
            } else {
                $name = $tokens->readName($i);
                if ($name === null) {
                    break;
                }
                $i = $tokens->isTrivia($name[1]) ? $tokens->nextCode($name[1]) : $name[1];
                $alias = null;
                if ($tokens->isId($i, T_AS)) {
                    $aliasAt = $tokens->nextCode($i);
                    $alias = $tokens->list[$aliasAt]['text'];
                    $i = $tokens->nextCode($aliasAt);
                }
                $classes[] = array($prefix . '\\' . \trim($name[0], '\\'), $alias);
            }
            if (!$tokens->is($i, ',')) {
                break;
            }
            $i = $tokens->nextCode($i);
        }
        return $classes;
    }

    /** @return int|null Index of the `;` ending the statement at $i, or null when it opens a block instead */
    private function statementEnd(Tokens $tokens, $i)
    {
        for ($j = $i + 1, $n = \count($tokens->list); $j < $n; $j++) {
            if ($tokens->depth[$j] !== $tokens->depth[$i]) {
                continue;
            }
            if ($tokens->is($j, ';')) {
                return $j;
            }
            if ($tokens->is($j, '{') && $tokens->list[$i]['id'] === T_NAMESPACE) {
                return null;
            }
        }
        return null;
    }

    /** @return int Offset just after the line ending of the line $offset is on */
    private function endOfLine($offset)
    {
        $newline = \strpos($this->source, "\n", $offset);
        return $newline === false ? \strlen($this->source) : $newline + 1;
    }
}
