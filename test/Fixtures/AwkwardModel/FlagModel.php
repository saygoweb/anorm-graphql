<?php

namespace Anorm\GraphQL\Test\Fixtures\AwkwardModel;

use Anorm\DataMapper;
use Anorm\Model;

/** Nothing to edit but Booleans: the generated test still needs something to update. */
class FlagModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'flags', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /** @var bool */
    public $active;

    /** @var bool */
    public $archived;
}
