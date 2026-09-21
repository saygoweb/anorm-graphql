<?php
namespace Anorm\GraphQL\Tools\Schema;

class SchemaEditResult
{
    /** @var string The source after editing; the input unchanged when $failed */
    public $source = '';
    /** @var bool true when the expected structure was not found and nothing was changed */
    public $failed = false;
    /** @var string[] One line each: collisions, orphans, or why the edit failed */
    public $messages = array();
    /** @var string[] The entries to paste by hand, filled only when $failed */
    public $paste = array();
}
