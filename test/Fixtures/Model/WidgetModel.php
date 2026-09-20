<?php

namespace Anorm\GraphQL\Test\Fixtures\Model;

use Anorm\DataMapper;
use Anorm\Model;

class WidgetModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'widgets', DataMapper::autoMap($this)));
        $this->belongsTo(OwnerModel::class, 'owner_id', 'id', 'owner');
    }

    /** @var int */
    public $id;

    /** @var string */
    public $name;

    /** @var int */
    public $quantity;

    /** @var float */
    public $price;

    /** @var bool */
    public $active;

    /** @var int */
    public $ownerId;

    public $notes;
}
