<?php

namespace Anorm\GraphQL\Test\Fixtures\AwkwardModel;

use Anorm\DataMapper;
use Anorm\Model;

/** A join table: nothing but its key and foreign keys, so nothing a generated test could sensibly update. */
class LinkModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'links', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /** @var int */
    public $ownerId;

    /** @var int */
    public $widgetId;
}
