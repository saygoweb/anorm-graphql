<?php

namespace Anorm\GraphQL\Test\Fixtures\OtherModel;

use Anorm\DataMapper;
use Anorm\Model;

/** Over a MyISAM table: whatever is written to it cannot be rolled back. */
class LegacyWidgetModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'legacy_widgets', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /** @var string */
    public $name;
}
