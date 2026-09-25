<?php
namespace Anorm\GraphQL\Tools\Writer;

use Anorm\GraphQL\Tools\TypeInfo;

class TypeBaseWriter
{
    const DEFAULT_BASE = 'Anorm\GraphQL\ModelType';

    /**
     * @param string $typeBase The class the base extends. The default keeps the output
     *   exactly as it always was; any other class is written fully qualified, so it
     *   cannot collide with a name this file imports.
     */
    public function render(TypeInfo $info, $typeNamespace, $typeBase = self::DEFAULT_BASE)
    {
        $namespace = Php::entityNamespace($typeNamespace, $info->entity) . '\\Base';
        $parts = \explode('\\', $info->modelClass);
        $modelShort = \end($parts);
        $typeBase = \ltrim((string) $typeBase, '\\');
        if (\strcasecmp($typeBase, self::DEFAULT_BASE) === 0) {
            $import = "use Anorm\\GraphQL\\ModelType;\n";
            $parent = 'ModelType';
        } else {
            $import = '';
            $parent = '\\' . $typeBase;
        }
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
{$import}use GraphQL\Type\Definition\Type;
use {$info->modelClass};

abstract class {$info->entity}TypeBase extends $parent
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
