<?php

namespace Anorm\GraphQL\Builder;

use GraphQL\Type\Definition\Type;

/**
 * Builds the config array of one field, of an object type or of an input type.
 *
 * The calls are the ones simpod/graphql-utils offers, which has no release for
 * PHP 7.4 on graphql-php 15; only what this package and its consumers use is here.
 */
class FieldBuilder
{
    /** @var array<string, mixed> */
    private $config;

    private function __construct(string $name, Type $type)
    {
        $this->config = ['name' => $name, 'type' => $type];
    }

    public static function create(string $name, Type $type): self
    {
        return new self($name, $type);
    }

    public function setDescription(string $description): self
    {
        $this->config['description'] = $description;
        return $this;
    }

    /**
     * @param mixed $defaultValue Only used when the call passes one; null is a valid default
     */
    public function addArgument(string $name, Type $type, ?string $description = null, $defaultValue = null): self
    {
        $argument = ['type' => $type];
        if ($description !== null) {
            $argument['description'] = $description;
        }
        if (func_num_args() >= 4) {
            $argument['defaultValue'] = $defaultValue;
        }
        $this->config['args'][$name] = $argument;
        return $this;
    }

    public function setResolver(callable $resolver): self
    {
        $this->config['resolve'] = $resolver;
        return $this;
    }

    public function setDeprecationReason(string $reason): self
    {
        $this->config['deprecationReason'] = $reason;
        return $this;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        return $this->config;
    }
}
