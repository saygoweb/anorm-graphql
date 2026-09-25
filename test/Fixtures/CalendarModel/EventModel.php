<?php

namespace Anorm\GraphQL\Test\Fixtures\CalendarModel;

use Anorm\DataMapper;
use Anorm\Model;

/** A model with a column that a create must supply: `@required`. */
class EventModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'events', DataMapper::autoMap($this)));
    }

    /** @var int */
    public $id;

    /**
     * @var string
     * @required
     */
    public $title;

    /** @var string */
    public $notes;
}
