<?php

namespace Anorm\GraphQL\Test\Fixtures\AwkwardModel;

use Anorm\DataMapper;
use Anorm\Model;

/** Its entity would be `List`, which PHP 7.4 does not allow as a namespace segment. */
class ListModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'lists', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /** @var string */
    public $name;
}
