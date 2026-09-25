<?php
namespace Anorm\GraphQL\Tools\Writer;

use Anorm\GraphQL\Tools\TypeInfo;

class InputWriter
{
    /** @param string $kind '' for the upsert Input, 'Create' or 'Update' */
    public function render(TypeInfo $info, $typeNamespace, $kind = '')
    {
        $namespace = Php::entityNamespace($typeNamespace, $info->entity);
        $name = $info->entity . $kind . 'Input';
        return <<<PHP
<?php

namespace $namespace;

use $namespace\\Base\\{$name}Base;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Override fields() to add or remove input fields:
 * array_merge(parent::fields(), [...])
 */
class {$name} extends {$name}Base
{
}

PHP;
    }
}
