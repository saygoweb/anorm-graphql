<?php

namespace Anorm\GraphQL\Test\Fixtures\Type;

use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Builder\ObjectBuilder;
use Anorm\GraphQL\ModelType;
use Anorm\GraphQL\Test\Fixtures\OtherModel\DocumentModel;
use GraphQL\Type\Definition\Type;

/** One kind of document: every row it sees, reads or writes has this `type`. */
class ScopedDocumentType extends ModelType
{
    /** @var int */
    private $documentType;

    /** @var string */
    private $model;

    public function __construct(string $name, int $documentType, string $model = DocumentModel::class)
    {
        $this->documentType = $documentType;
        $this->model = $model;
        parent::__construct(ObjectBuilder::create($name)->setFields($this->fields())->build());
    }

    protected function modelClass(): string
    {
        return $this->model;
    }

    protected function fields(): array
    {
        return [
            FieldBuilder::create('id', Type::nonNull(Type::id()))->build(),
            FieldBuilder::create('title', Type::string())->build(),
        ];
    }

    protected function scope(): array
    {
        return ['type' => $this->documentType];
    }
}
