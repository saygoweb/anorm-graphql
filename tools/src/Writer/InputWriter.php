<?php
namespace Anorm\GraphQL\Tools\Writer;

use Anorm\GraphQL\Tools\TypeInfo;

class InputWriter
{
    public function render(TypeInfo $info, $typeNamespace)
    {
        $namespace = Php::entityNamespace($typeNamespace, $info->entity);
        return <<<PHP
<?php

namespace $namespace;

use $namespace\\Base\\{$info->entity}InputBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Override fields() to add or remove input fields:
 * array_merge(parent::fields(), [...])
 */
class {$info->entity}Input extends {$info->entity}InputBase
{
}

PHP;
    }
}
