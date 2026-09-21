<?php
namespace Anorm\GraphQL\Tools\Schema;

/** The interior of one `'fields' => [ ... ]` array, split into entries. */
class FieldsArray
{
    /** @var int Byte offset just after the opening `[` */
    public $start = 0;
    /** @var int Byte offset of the closing `]` */
    public $end = 0;
    /** @var string Whitespace that starts the line holding the opening `[` */
    public $bracketIndent = '';
    /** @var FieldsEntry[] */
    public $entries = array();
    /** @var string Whatever follows the last entry, before the closing `]` */
    public $tail = '';
}
