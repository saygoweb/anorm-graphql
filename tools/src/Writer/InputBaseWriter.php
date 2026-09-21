<?php
namespace Anorm\GraphQL\Tools\Writer;

use Anorm\GraphQL\Tools\TypeInfo;

class InputBaseWriter
{
    public function render(TypeInfo $info, $typeNamespace)
    {
        $namespace = Php::entityNamespace($typeNamespace, $info->entity) . '\\Base';
        $fields = '';
        foreach ($info->fields as $name => $type) {
            // The key is nullable here: an input without one is a create.
            $fields .= "            FieldBuilder::create('$name', " . Php::TYPE_CALLS[$type] . ")->build(),\n";
        }
        $header = Php::HEADER;
        return <<<PHP
<?php

$header — do not edit. Changes belong in {$info->entity}Input.php.

namespace $namespace;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

abstract class {$info->entity}InputBase extends InputObjectType
{
    public function __construct()
    {
        parent::__construct(
            ObjectBuilder::create('{$info->entity}Input')->setFields(\$this->fields())->build()
        );
    }

    protected function fields(): array
    {
        return [
$fields        ];
    }
}

PHP;
    }
}
