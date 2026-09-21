<?php

namespace Anorm\GraphQL\Test\Fixtures\Model;

use Anorm\DataMapper;
use Anorm\Model;

/** A table with a composite key: there is no single key property to find. */
class LedgerLineModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'ledger_lines', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $type;

    /** @var int */
    public $transNo;

    /** @var float */
    public $amount;
}
