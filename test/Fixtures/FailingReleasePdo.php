<?php

namespace Anorm\GraphQL\Test\Fixtures;

/**
 * A real connection whose RELEASE SAVEPOINT fails the way a dropped connection does:
 * a driver error that has nothing to do with an implicit commit.
 */
class FailingReleasePdo extends \PDO
{
    /**
     * Untyped so the one declaration is valid on PHP 7.4 and 8.x alike.
     *
     * @param string $statement
     * @return int|false
     */
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        if (strpos($statement, 'RELEASE SAVEPOINT') === 0) {
            $failure = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            $failure->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
            throw $failure;
        }
        return parent::exec($statement);
    }
}
