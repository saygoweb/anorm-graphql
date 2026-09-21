<?php
namespace Anorm\GraphQL\Tools\Writer;

use Anorm\GraphQL\Tools\TypeInfo;

class TestWriter
{
    public function render(TypeInfo $info, $typeNamespace, $testNamespace)
    {
        $testNamespace = \trim($testNamespace, '\\');
        $entityNamespace = Php::entityNamespace($typeNamespace, $info->entity);
        $uses = "use $entityNamespace\\{$info->entity}Type;\n";
        $inputClass = 'null';
        if (!$info->readOnly) {
            $uses = "use $entityNamespace\\{$info->entity}Input;\n" . $uses;
            $inputClass = "{$info->entity}Input::class";
        }

        $expected = '';
        $sample = '';
        $update = '';
        $updateIsBoolean = false;
        $foreignKeys = array();
        foreach ($info->fields as $name => $type) {
            $printed = $name === $info->keyProperty ? $type . '!' : $type;
            $expected .= "            '$name' => '$printed',\n";
            if ($name === $info->keyProperty) {
                continue;
            }
            if ($type === 'ID') {
                $foreignKeys[] = $name;
                continue;
            }
            $sample .= "            '$name' => " . Php::export($this->sampleValue($name, $type, 1)) . ",\n";
            // Prefer a field whose second value is visibly different; a Boolean will do if that is all there is.
            if ($update === '' || ($updateIsBoolean && $type !== 'Boolean')) {
                $update = "            '$name' => " . Php::export($this->sampleValue($name, $type, 2)) . ",\n";
                $updateIsBoolean = $type === 'Boolean';
            }
        }
        $foreignKeyNote = '';
        if ($foreignKeys) {
            $foreignKeyNote = "        // Foreign keys are left out: the generator cannot know a valid parent row.\n"
                . "        // If any is required, create the parent here and add: " . \implode(', ', $foreignKeys) . "\n";
        }
        $updateNote = '';
        if ($update === '') {
            $updateNote = "        // Nothing to update could be chosen: every field is the key or a foreign key.\n"
                . "        // Until this returns a field, the test reports itself incomplete rather than pass in silence.\n";
        }
        $keyField = Php::export($info->keyProperty);

        return <<<PHP
<?php

namespace $testNamespace;

$uses
/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 * The tests themselves are inherited from Anorm\\GraphQL\\Testing\\ModelTypeTestCase.
 */
class {$info->entity}TypeTest extends TestCase
{
    protected function typeClass(): string
    {
        return {$info->entity}Type::class;
    }

    protected function inputClass(): ?string
    {
        return $inputClass;
    }

    protected function entityName(): string
    {
        return '{$info->fieldPrefix()}';
    }

    protected function keyField(): string
    {
        return $keyField;
    }

    protected function expectedFieldTypes(): array
    {
        return [
$expected        ];
    }

    protected function sampleInput(): array
    {
$foreignKeyNote        return [
$sample        ];
    }

    protected function sampleUpdate(): array
    {
$updateNote        return [
$update        ];
    }
}

PHP;
    }

    /** @return mixed */
    private function sampleValue($name, $type, $n)
    {
        switch ($type) {
            case 'Int':
                return $n;
            case 'Float':
                return $n + 0.5;
            case 'Boolean':
                return $n === 1;
            default:
                return $name . ' ' . $n;
        }
    }
}
