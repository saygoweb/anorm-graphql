<?php

namespace Anorm\GraphQL\Test\Fixtures\CalendarModel;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\SqlDateTimeTransform;

/** A model with a column that a create must supply: `@required`. */
class EventModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'events', DataMapper::autoMap($this)));
        // A DATE column: Anorm hands the model a \DateTime and writes it back as Y-m-d.
        $this->mapper()->transformers['due_on'] = new SqlDateTimeTransform('Y-m-d');
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

    /** @var \DateTime */
    public $dueOn;
}
