<?php
namespace Anorm\GraphQL\Tools;

use Anorm\Model;
use Anorm\Schema\PropertyType;

/** Works out a TypeInfo from an Anorm model. */
class TypeInfoBuilder
{
    /** @var array<string, string> Model class => why no Type can be generated for it */
    public $skipped = array();

    /** @var string */
    private $classSuffix;

    public function __construct($classSuffix = 'Model')
    {
        $this->classSuffix = $classSuffix;
    }

    /**
     * @param string $modelClass
     * @return string Short class name minus the suffix, e.g. 'Client'
     */
    public function entityName($modelClass)
    {
        $parts = \explode('\\', $modelClass);
        $short = \end($parts);
        $length = \strlen($this->classSuffix);
        if ($length > 0 && \strlen($short) > $length && \substr($short, -$length) === $this->classSuffix) {
            $short = \substr($short, 0, -$length);
        }
        return $short;
    }

    /**
     * @param Model $model
     * @param bool $readOnly
     * @return TypeInfo|null null when skipped; the reason is in $skipped
     */
    public function build(Model $model, $readOnly = false)
    {
        $class = \get_class($model);
        $mapper = $model->mapper();
        $key = (string) $mapper->modelPrimaryKey;
        if ($key === '' || !\property_exists($model, $key)) {
            $this->skipped[$class] = "no single key: '$key' is not a property of the model";
            return null;
        }

        $info = new TypeInfo();
        $info->entity = $this->entityName($class);
        $info->modelClass = $class;
        $info->keyProperty = $key;
        $info->readOnly = (bool) $readOnly;

        $declared = PropertyType::forClass($class);
        $relationships = $model->getRelationshipManager();
        foreach (\array_keys($mapper->map) as $property) {
            if ($property === '' || $property[0] === '_' || !\property_exists($model, $property)) {
                continue;
            }
            if ($relationships->hasRelationship($property)) {
                continue;
            }
            $type = isset($declared[$property]) ? $declared[$property] : null;
            if ($type === 'array' || $this->isModel($type, $class)) {
                continue;
            }
            $info->fields[$property] = $this->graphQLType($property, $key, $type, $class);
            if ($property !== $key && $this->isRequired($class, $property)) {
                $info->required[] = $property;
            }
        }
        // The key leads, whatever order the model declares its properties in.
        $info->fields = array($key => 'ID') + $info->fields;
        return $info;
    }

    /**
     * Whether a declared type names an Anorm model: a related object, not a column.
     * A docblock usually gives the short name, so the model's own namespace is tried too.
     *
     * @param string|null $type
     * @param string $modelClass The class declaring the property
     */
    private function isModel($type, $modelClass)
    {
        if ($type === null) {
            return false;
        }
        $namespace = \substr($modelClass, 0, (int) \strrpos($modelClass, '\\'));
        foreach (array(\ltrim($type, '\\'), $namespace . '\\' . $type) as $candidate) {
            if (\class_exists($candidate) && \is_subclass_of($candidate, Model::class)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a create must supply this property: its docblock carries `@required`.
     * The model says so; the generator never asks the database.
     */
    private function isRequired($class, $property)
    {
        $doc = (new \ReflectionProperty($class, $property))->getDocComment();
        return \is_string($doc) && \preg_match('/@required\b/', $doc) === 1;
    }

    /**
     * In precedence order: the key, then an `Id` suffix, then the declared type.
     */
    private function graphQLType($property, $key, $declared, $modelClass)
    {
        if ($property === $key || \substr($property, -2) === 'Id') {
            return 'ID';
        }
        switch ($declared) {
            case 'int':
                return 'Int';
            case 'float':
                return 'Float';
            case 'bool':
                return 'Boolean';
            default:
                return $this->isDate($property, $declared, $modelClass) ? 'Date' : 'String';
        }
    }

    /**
     * Whether a declared type is a date: \DateTimeInterface or a class implementing it.
     * A docblock usually gives the name as written, so the model's own namespace is tried too.
     *
     * A property with a *native* PHP type has that type enforced at assignment, and
     * parseValue()/parseLiteral() always hand back a \DateTimeImmutable — so a native
     * type it cannot satisfy (typed \DateTime, which is unrelated to \DateTimeImmutable)
     * would throw a TypeError on every create or update. Only a native type
     * \DateTimeImmutable itself is happy with is treated as Date; an @var-only
     * declaration is never enforced by PHP, so it is unaffected.
     *
     * @param string $property
     * @param string|null $type
     * @param string $modelClass
     */
    private function isDate($property, $type, $modelClass)
    {
        if ($type === null || \in_array($type, array('string', 'array'), true)) {
            return false;
        }
        $namespace = \substr($modelClass, 0, (int) \strrpos($modelClass, '\\'));
        $isDateClass = false;
        foreach (array(\ltrim($type, '\\'), $namespace . '\\' . $type) as $candidate) {
            if ((\class_exists($candidate) || \interface_exists($candidate)) && \is_a($candidate, \DateTimeInterface::class, true)) {
                $isDateClass = true;
                break;
            }
        }
        if (!$isDateClass) {
            return false;
        }
        $native = (new \ReflectionProperty($modelClass, $property))->getType();
        if ($native instanceof \ReflectionNamedType && !\is_a(\DateTimeImmutable::class, $native->getName(), true)) {
            return false;
        }
        return true;
    }
}
