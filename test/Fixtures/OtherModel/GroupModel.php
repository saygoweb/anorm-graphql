<?php

namespace Anorm\GraphQL\Test\Fixtures\OtherModel;

use Anorm\DataMapper;
use Anorm\GraphQL\Test\Fixtures\Model\WidgetModel;
use Anorm\Model;

/**
 * Declares things that are not columns. It lives outside Fixtures/Model so that the
 * TypeMaker and end-to-end tests, which generate from that directory, never see it.
 */
class GroupModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::create($pdo, 'groups', DataMapper::autoMap($this)));
        $this->hasMany(WidgetModel::class, 'group_id', 'id', 'widgets');
    }

    /** @var string */
    public $title;

    /** @var int */
    public $id;

    /** @var WidgetModel[] A relationship, not a column */
    public $widgets;

    /** @var string[] An array, not a column */
    public $tags;

    /** @var \Anorm\GraphQL\Test\Fixtures\Model\WidgetModel A model in another namespace, fully qualified */
    public $favourite;

    /** @var OtherGroupModel Same namespace, short name, and not imported */
    public $parent;
}
