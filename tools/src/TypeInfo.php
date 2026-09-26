<?php
namespace Anorm\GraphQL\Tools;

/** Everything the writers need to know about one entity. */
class TypeInfo
{
    /** @var string Entity name: the model's short class name minus the suffix, e.g. 'Client' */
    public $entity = '';
    /** @var string Fully qualified model class, no leading backslash */
    public $modelClass = '';
    /** @var string The model's key property, e.g. 'id' */
    public $keyProperty = '';
    /** @var array<string, string> Property name => 'ID', 'Int', 'Float', 'Boolean', 'String' or 'Date' */
    public $fields = array();
    /** @var bool true to emit no Input and no mutations */
    public $readOnly = false;
    /** @var string 'upsert', or 'create-update' for separate create and update mutations */
    public $mutations = 'upsert';
    /** @var string[] Non-key properties a create must supply: their docblock says `@required` */
    public $required = array();
    /** @var bool true to emit the Input(s) only: no mutations, and no Type unless also read-only */
    public $inputOnly = false;
    /** @var bool true to emit no Update mutation and no Update input; only meaningful
     *  with $mutations === 'create-update' */
    public $withoutUpdate = false;
    /** @var bool true to emit no Delete mutation */
    public $withoutDelete = false;

    /** @return string The prefix of this entity's schema fields, e.g. 'client' */
    public function fieldPrefix()
    {
        return \lcfirst($this->entity);
    }
}
