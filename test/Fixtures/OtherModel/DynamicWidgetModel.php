<?php

namespace Anorm\GraphQL\Test\Fixtures\OtherModel;

use Anorm\DataMapper;
use Anorm\Model;

/** A model in Anorm's dynamic mode, which runs DDL during a write. */
class DynamicWidgetModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::create($pdo, 'widgets', DataMapper::autoMap($this));
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
    }

    /** @var int */
    public $id;

    /** @var string */
    public $name;
}
