<?php
namespace Anorm\GraphQL\Tools\Schema;

/** One element of a fields array, with the whitespace and comments that lead it. */
class FieldsEntry
{
    /** @var string Exact source text, from after the previous comma to this entry's comma */
    public $text = '';
    /** @var string|null The GraphQL field name: the first string literal in the entry */
    public $name = null;
    /** @var bool true when the entry is led by the marker comment */
    public $owned = false;
    /** @var bool true when the entry ends with its own comma */
    public $hasComma = false;
    /** @var string Text before the marker comment, or before the code when unowned */
    public $lead = '';
    /** @var string Indentation of the entry's first line of code */
    public $indent = '';
}
