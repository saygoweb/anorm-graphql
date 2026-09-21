<?php

namespace Anorm\GraphQL\Test\Fixtures\AwkwardModel;

use Anorm\DataMapper;
use Anorm\Model;

/** With GadgetModel, a second model whose entity is `Gadget`: the suffix is optional. */
class Gadget extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'gadgets', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /** @var string */
    public $name;
}
