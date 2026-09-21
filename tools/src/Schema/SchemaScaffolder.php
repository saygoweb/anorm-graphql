<?php
namespace Anorm\GraphQL\Tools\Schema;

/** Writes the ApiSchema.php a project starts from. SchemaEditor fills it in. */
class SchemaScaffolder
{
    public function render($namespace, $className = 'ApiSchema')
    {
        $namespace = \trim($namespace, '\\');
        return <<<PHP
<?php

namespace $namespace;

use DI\\Container;
use GraphQL\\Type\\Definition\\ObjectType;
use GraphQL\\Type\\Schema;

/**
 * Yours to edit. anorm-graphql maintains only the entries under an
 * `// anorm-graphql` comment; remove the comment to take an entry over.
 * Keep the entries of each fields array in alphabetical order.
 */
class $className extends Schema
{
    /** @var Container */
    public \$context;

    public function __construct(Container \$context)
    {
        \$this->context = \$context;

        \$object = [
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                ],
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => [
                ],
            ]),
        ];
        parent::__construct(\$object);
    }

    private function type(string \$name)
    {
        return \$this->context->get(\$name);
    }
}

PHP;
    }
}
