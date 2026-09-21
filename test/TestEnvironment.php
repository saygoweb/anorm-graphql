<?php

namespace Anorm\GraphQL\Test;

use DI\Container;
use DI\ContainerBuilder;

/**
 * The integration suites' database. The environment wins over the defaults, so the
 * docker stack's database is used whatever else is lying about.
 */
class TestEnvironment
{
    /** @var \PDO|null */
    private static $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $name = getenv('DB_NAME') ?: 'anorm_graphql_test';
            $user = getenv('DB_USER') ?: 'dev';
            $pass = getenv('DB_PASS') ?: 'dev';
            self::$pdo = new \PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        return self::$pdo;
    }

    /** A container that hands every model the same PDO. */
    public static function container(): Container
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions([\PDO::class => self::pdo()]);
        return $builder->build();
    }

    /**
     * Drop and create the fixture tables. DDL commits implicitly in MySQL, so this
     * belongs in setUpBeforeClass, never inside a test's transaction.
     */
    public static function createTables(): void
    {
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS `widgets`');
        $pdo->exec('DROP TABLE IF EXISTS `owners`');
        $pdo->exec('DROP TABLE IF EXISTS `documents`');
        $pdo->exec(
            'CREATE TABLE `owners` (
                `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NULL
            ) ENGINE=InnoDB'
        );
        $pdo->exec(
            'CREATE TABLE `documents` (
                `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `type` INT NOT NULL,
                `title` VARCHAR(255) NULL
            ) ENGINE=InnoDB'
        );
        $pdo->exec(
            "CREATE TABLE `widgets` (
                `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NULL,
                `quantity` INT NULL,
                `price` DOUBLE NULL,
                `active` TINYINT(1) NULL,
                `owner_id` INT NULL,
                `notes` VARCHAR(255) NULL
            ) ENGINE=InnoDB"
        );
    }
}
