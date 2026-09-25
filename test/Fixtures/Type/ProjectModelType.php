<?php

namespace Anorm\GraphQL\Test\Fixtures\Type;

use Anorm\GraphQL\ModelType;

/**
 * A project's own base for generated Types, as `--type-base` names one. It adds a
 * method so a test can tell a Type built on it from one built on ModelType.
 */
abstract class ProjectModelType extends ModelType
{
    public function projectBase(): string
    {
        return 'project';
    }
}
