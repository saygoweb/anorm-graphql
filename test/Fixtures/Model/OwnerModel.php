<?php

namespace Anorm\GraphQL\Test\Fixtures\Model;

use Anorm\DataMapper;
use Anorm\Model;

class OwnerModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'owners', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /** @var string */
    public $name;
}
