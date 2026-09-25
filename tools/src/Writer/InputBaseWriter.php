<?php
namespace Anorm\GraphQL\Tools\Writer;

use Anorm\GraphQL\Tools\TypeInfo;

class InputBaseWriter
{
    /**
     * @param string $kind '' for the upsert Input, 'Create' or 'Update'. '' keeps the output
     *   exactly as it always was.
     */
    public function render(TypeInfo $info, $typeNamespace, $kind = '')
    {
        $namespace = Php::entityNamespace($typeNamespace, $info->entity) . '\\Base';
        $name = $info->entity . $kind . 'Input';
        $fields = '';
        foreach ($info->fields as $field => $type) {
            $call = Php::TYPE_CALLS[$type];
            if ($kind === 'Create') {
                if ($field === $info->keyProperty) {
                    // A create makes the key.
                    continue;
                }
                if (\in_array($field, $info->required, true)) {
                    $call = 'Type::nonNull(' . $call . ')';
                }
            } elseif ($kind === 'Update' && $field === $info->keyProperty) {
                // An update names its row.
                $call = 'Type::nonNull(' . $call . ')';
            }
            // Upsert: the key is nullable, because an input without one is a create.
            $fields .= "            FieldBuilder::create('$field', $call)->build(),\n";
        }
        $header = Php::HEADER;
        return <<<PHP
<?php

$header — do not edit. Changes belong in {$name}.php.

namespace $namespace;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

abstract class {$name}Base extends InputObjectType
{
    public function __construct()
    {
        parent::__construct(
            ObjectBuilder::create('{$name}')->setFields(\$this->fields())->build()
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
