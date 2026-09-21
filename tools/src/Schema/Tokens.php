<?php
namespace Anorm\GraphQL\Tools\Schema;

/**
 * A PHP source as tokens with byte offsets and bracket depths.
 *
 * Written to give the same answers on PHP 7.4 and 8.x, whose tokenizers differ: 8.x
 * has single tokens for qualified names, and an attribute `#[` opens a bracket there
 * while being a comment on 7.4.
 */
class Tokens
{
    /** @var array<int, array{id: int|null, text: string, offset: int}> */
    public $list = array();
    /** @var int[] Bracket depth before each token; an opening bracket has the depth of its surroundings */
    public $depth = array();
    /** @var array<int, int> Index of an opening bracket => index of its closing bracket */
    public $closes = array();
    /** @var array<int, int|null> Index of a token => index of the bracket that encloses it */
    public $parent = array();

    public function __construct($source)
    {
        $offset = 0;
        foreach (\token_get_all($source) as $token) {
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? $token[1] : $token;
            $this->list[] = array('id' => $id, 'text' => $text, 'offset' => $offset);
            $offset += \strlen($text);
        }
        $stack = array();
        foreach ($this->list as $i => $token) {
            if ($this->isClose($token)) {
                $open = \array_pop($stack);
                if ($open === null) {
                    throw new SchemaShapeException('unbalanced brackets');
                }
                $this->closes[$open] = $i;
            }
            $this->depth[$i] = \count($stack);
            $this->parent[$i] = $stack ? $stack[\count($stack) - 1] : null;
            if ($this->isOpen($token)) {
                $stack[] = $i;
            }
        }
        if ($stack) {
            throw new SchemaShapeException('unbalanced brackets');
        }
    }

    public function isOpen(array $token)
    {
        if ($token['id'] === null) {
            return $token['text'] === '[' || $token['text'] === '(' || $token['text'] === '{';
        }
        return $token['id'] === T_CURLY_OPEN
            || $token['id'] === T_DOLLAR_OPEN_CURLY_BRACES
            || (\defined('T_ATTRIBUTE') && $token['id'] === \constant('T_ATTRIBUTE'));
    }

    public function isClose(array $token)
    {
        return $token['id'] === null
            && ($token['text'] === ']' || $token['text'] === ')' || $token['text'] === '}');
    }

    public function isTrivia($i)
    {
        $id = $this->list[$i]['id'];
        return $id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT;
    }

    /** @return int|null Index of the next token after $i that is not whitespace or a comment */
    public function nextCode($i)
    {
        for ($j = $i + 1, $n = \count($this->list); $j < $n; $j++) {
            if (!$this->isTrivia($j)) {
                return $j;
            }
        }
        return null;
    }

    public function isString($i, $value = null)
    {
        if ($i === null || $this->list[$i]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            return false;
        }
        return $value === null || $this->stringValue($i) === $value;
    }

    public function stringValue($i)
    {
        return \substr($this->list[$i]['text'], 1, -1);
    }

    public function is($i, $text)
    {
        return $i !== null && $this->list[$i]['id'] === null && $this->list[$i]['text'] === $text;
    }

    public function isId($i, $id)
    {
        return $i !== null && $this->list[$i]['id'] === $id;
    }

    /**
     * Read a class name starting at $i, however this PHP version tokenizes it.
     *
     * @return array{0: string, 1: int}|null The name, and the index of the token after it
     */
    public function readName($i)
    {
        $name = '';
        $n = \count($this->list);
        while ($i < $n) {
            if ($this->isNamePart($i)) {
                $name .= $this->list[$i]['text'];
                $i++;
                continue;
            }
            // PHP 7.4 allows whitespace and comments around the separators of a name
            // (`Foo\` newline `Bar`); 8.x does not, and never gets here with such a file.
            $next = $this->isTrivia($i) ? $this->nextCode($i) : null;
            $joins = $next !== null && $name !== '' && $this->isNamePart($next)
                && (\substr($name, -1) === '\\' || $this->list[$next]['id'] === T_NS_SEPARATOR);
            if (!$joins) {
                break;
            }
            $i = $next;
        }
        return $name === '' ? null : array($name, $i);
    }

    /** Whether the token is a qualified name in one piece, as PHP 8.x produces. */
    public function isQualifiedNameToken($i)
    {
        foreach (array('T_NAME_QUALIFIED', 'T_NAME_RELATIVE') as $constant) {
            if (\defined($constant) && $this->list[$i]['id'] === \constant($constant)) {
                return true;
            }
        }
        return false;
    }

    private function isNamePart($i)
    {
        $id = $this->list[$i]['id'];
        if ($id === T_STRING || $id === T_NS_SEPARATOR) {
            return true;
        }
        foreach (array('T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE') as $constant) {
            if (\defined($constant) && $id === \constant($constant)) {
                return true;
            }
        }
        return false;
    }
}
