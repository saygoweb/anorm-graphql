<?php
namespace Anorm\GraphQL\Tools\Schema;

/**
 * One element of a fields array: whole lines of source, from the start of the line its
 * leading comments begin on to the end of the line its comma is on.
 */
class FieldsEntry
{
    /** @var string Exact source text of the entry */
    public $text = '';
    /** @var string[] Field names the entry defines; empty when none can be read */
    public $names = array();
    /** @var bool true when the marker comment is the last thing before the code */
    public $owned = false;
    /** @var string Text before the marker line; only meaningful when owned */
    public $beforeMarker = '';
    /** @var string Indentation of the entry's first line of code */
    public $indent = '';
    /** @var int|null Offset within $text just after the last code token, when the entry has no comma */
    public $missingCommaAt = null;
    /** @var string What follows the comma on its line: a trailing comment, and the line ending */
    public $afterComma = '';

    /** @return string|null The name that decides where the entry sorts */
    public function primaryName()
    {
        return $this->names ? $this->names[0] : null;
    }
}
