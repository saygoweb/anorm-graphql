<?php

namespace Anorm\GraphQL\Test\Fixtures\AwkwardModel;

use Anorm\DataMapper;
use Anorm\Model;

/** `parent` is reserved only as a bare class name. As an entity it is fine: ParentType, and a namespace segment. */
class ParentModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'parents', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /** @var string */
    public $name;
}
