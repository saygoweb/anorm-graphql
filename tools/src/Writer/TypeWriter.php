<?php
namespace Anorm\GraphQL\Tools\Writer;

use Anorm\GraphQL\Tools\TypeInfo;

class TypeWriter
{
    public function render(TypeInfo $info, $typeNamespace)
    {
        $namespace = Php::entityNamespace($typeNamespace, $info->entity);
        $resolvers = $info->mutations === 'create-update'
            ? 'resolveList / resolveCreate / resolveUpdate / resolveDelete'
            : 'resolveList / resolveUpsert / resolveDelete';
        return <<<PHP
<?php

namespace $namespace;

use $namespace\\Base\\{$info->entity}TypeBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Override points, all inherited from Anorm\\GraphQL\\ModelType:
 *  - authorize(\$verb, \$model, \$context)   throw to refuse 'list', 'create', 'edit' or 'delete'
 *  - beforeWrite(\$model, \$input, \$isUpdate, \$context)   stamp columns before a write
 *  - newModel(\$context)   construct the model some other way
 *  - $resolvers   replace a resolver outright
 *  - fields()   add computed fields: array_merge(parent::fields(), [...])
 */
class {$info->entity}Type extends {$info->entity}TypeBase
{
}

PHP;
    }
}
