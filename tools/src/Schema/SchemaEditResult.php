<?php
namespace Anorm\GraphQL\Tools\Schema;

class SchemaEditResult
{
    /** @var string The source after editing; the input, byte for byte, when $failed */
    public $source = '';
    /** @var bool true when the file was not in a shape the editor is sure of, and nothing was changed */
    public $failed = false;
    /** @var string[] One line each: collisions, orphans, skipped entities, or why the edit failed */
    public $messages = array();
    /** @var array<string, string[]> 'query' and 'mutation' => entries to paste by hand; filled only when $failed */
    public $paste = array('query' => array(), 'mutation' => array());
}
