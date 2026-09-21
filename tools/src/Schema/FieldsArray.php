<?php
namespace Anorm\GraphQL\Tools\Schema;

/** The inside of one `'fields' => [ ... ]` array. */
class FieldsArray
{
    /** @var int Byte offset just after the opening `[` */
    public $start = 0;
    /** @var int Byte offset of the closing `]` */
    public $end = 0;
    /** @var string Whitespace that starts the line holding the opening `[` */
    public $bracketIndent = '';
    /** @var string The rest of the opening bracket's line, line ending included */
    public $head = '';
    /** @var FieldsEntry[] */
    public $entries = array();
    /** @var string Whatever follows the last entry: comment-only lines, and the indentation of `]` */
    public $tail = '';
    /** @var bool true for `[]` written on one line */
    public $emptyOnOneLine = false;
}
