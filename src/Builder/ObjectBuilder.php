<?php

namespace Anorm\GraphQL\Builder;

/**
 * Builds the config array of an ObjectType or an InputObjectType.
 *
 * @see FieldBuilder for why this is not simpod/graphql-utils
 */
class ObjectBuilder
{
    /** @var array<string, mixed> */
    private $config;

    private function __construct(string $name)
    {
        $this->config = ['name' => $name, 'fields' => []];
    }

    public static function create(string $name): self
    {
        return new self($name);
    }

    public function setDescription(string $description): self
    {
        $this->config['description'] = $description;
        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $fields Each the result of FieldBuilder::build()
     */
    public function setFields(array $fields): self
    {
        $this->config['fields'] = $fields;
        return $this;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        return $this->config;
    }
}
