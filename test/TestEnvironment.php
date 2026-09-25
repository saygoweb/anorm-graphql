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
            self::$pdo = self::connect();
        }
        return self::$pdo;
    }

    /**
     * A connection of its own, for a test that must break one without breaking the rest.
     *
     * @param string $class \PDO or a subclass of it
     */
    public static function connect(string $class = \PDO::class): \PDO
    {
        $host = getenv('DB_HOST') ?: 'db';
        $name = getenv('DB_NAME') ?: 'anorm_graphql_test';
        $user = getenv('DB_USER') ?: 'dev';
        $pass = getenv('DB_PASS') ?: 'dev';
        $pdo = new $class("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** A container that hands every model the same PDO: the shared one unless given another. */
    public static function container(?\PDO $pdo = null): Container
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions([\PDO::class => $pdo === null ? self::pdo() : $pdo]);
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
        $pdo->exec('DROP TABLE IF EXISTS `legacy_widgets`');
        $pdo->exec('DROP TABLE IF EXISTS `events`');
        $pdo->exec(
            'CREATE TABLE `owners` (
                `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NULL
            ) ENGINE=InnoDB'
        );
        // MyISAM has no transactions, as older applications' tables often do not.
        $pdo->exec(
            'CREATE TABLE `legacy_widgets` (
                `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NULL
            ) ENGINE=MyISAM'
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
        $pdo->exec(
            "CREATE TABLE `events` (
                `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `notes` VARCHAR(255) NULL,
                `due_on` DATE NULL
            ) ENGINE=InnoDB"
        );
    }
}
