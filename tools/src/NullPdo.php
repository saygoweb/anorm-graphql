<?php
namespace Anorm\GraphQL\Tools;

/**
 * A PDO that connects to nothing.
 *
 * Generating Types needs each model constructed, to ask its mapper for the key
 * property, and a model's constructor wants a PDO. Anorm only stores it and sets
 * the error mode on it, so this is enough, and generation needs neither a database
 * nor a PDO driver. A model whose constructor runs a query will throw, and is
 * reported as skipped.
 */
class NullPdo extends \PDO
{
    public function __construct()
    {
    }

    /**
     * Anorm\Model's constructor sets the error mode; accept that and do nothing.
     * Untyped so the one declaration is valid on PHP 7.4 and 8.x alike.
     *
     * @param int $attribute
     * @param mixed $value
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function setAttribute($attribute, $value)
    {
        return true;
    }
}
