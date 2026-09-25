<?php

namespace Anorm\GraphQL\Test\Fixtures\Type;

use Anorm\GraphQL\ModelType;
use Anorm\GraphQL\Test\Fixtures\Model\WidgetModel;

/** A ModelType nothing can extend: `--type-base` must refuse it. */
final class FinalModelType extends ModelType
{
    protected function modelClass(): string
    {
        return WidgetModel::class;
    }

    protected function fields(): array
    {
        return [];
    }
}
