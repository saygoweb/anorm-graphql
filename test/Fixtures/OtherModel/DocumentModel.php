<?php

namespace Anorm\GraphQL\Test\Fixtures\OtherModel;

use Anorm\DataMapper;
use Anorm\Model;

/**
 * One table, several kinds of row, told apart by `type`: the shape of FrontAccounting's
 * debtor_trans. `type` is also a word Anorm's Mango parser reads as an operator.
 * Outside Fixtures/Model, so the generator tests never see it.
 */
class DocumentModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'documents', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /** @var int */
    public $type;

    /** @var string */
    public $title;
}
