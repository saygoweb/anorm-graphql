<?php
namespace Anorm\GraphQL\Tools\Writer;

use Anorm\GraphQL\Tools\TypeInfo;

class TypeBaseWriter
{
    public function render(TypeInfo $info, $typeNamespace)
    {
        $namespace = Php::entityNamespace($typeNamespace, $info->entity) . '\\Base';
        $parts = \explode('\\', $info->modelClass);
        $modelShort = \end($parts);
        $fields = '';
        foreach ($info->fields as $name => $type) {
            $call = Php::TYPE_CALLS[$type];
            if ($name === $info->keyProperty) {
                $call = 'Type::nonNull(' . $call . ')';
            }
            $fields .= "            FieldBuilder::create('$name', $call)->build(),\n";
        }
        $header = Php::HEADER;
        return <<<PHP
<?php

$header — do not edit. Changes belong in {$info->entity}Type.php.

namespace $namespace;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use Anorm\GraphQL\ModelType;
use GraphQL\Type\Definition\Type;
use {$info->modelClass};

abstract class {$info->entity}TypeBase extends ModelType
{
    public function __construct()
    {
        parent::__construct(
            ObjectBuilder::create('{$info->entity}Type')->setFields(\$this->fields())->build()
        );
    }

    protected function modelClass(): string
    {
        return $modelShort::class;
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
