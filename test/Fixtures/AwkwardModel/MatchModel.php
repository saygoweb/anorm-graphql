<?php

namespace Anorm\GraphQL\Test\Fixtures\AwkwardModel;

use Anorm\DataMapper;
use Anorm\Model;

/** `match` is a keyword from PHP 8, where keywords are allowed in namespaces; on 7.4 it is no keyword at all. */
class MatchModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'matches', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /** @var string */
    public $type;

    /** @var int */
    public $list;
}
