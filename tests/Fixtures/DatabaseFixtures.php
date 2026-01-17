<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use Doctrine\DBAL\Connection;

final class DatabaseFixtures
{
    /**
     * Setup schema and load fixtures in one call.
     */
    public static function setup(Connection $connection): void
    {
        self::setupSchema($connection);
        self::loadFixtures($connection);
    }

    /**
     * Teardown database (drop tables).
     */
    public static function teardown(Connection $connection): void
    {
        $type = self::detectDatabaseType($connection);

        // Drop tables in reverse order due to potential foreign keys
        $connection->executeStatement('DROP TABLE IF EXISTS products');
        $connection->executeStatement('DROP TABLE IF EXISTS users');
    }

    /**
     * Create the database schema (users and products tables).
     */
    public static function setupSchema(Connection $connection): void
    {
        $type = self::detectDatabaseType($connection);

        self::createUsersTable($connection, $type);
        self::createProductsTable($connection, $type);
    }

    /**
     * Load sample data into the database.
     */
    public static function loadFixtures(Connection $connection): void
    {
        $type = self::detectDatabaseType($connection);

        $users = self::getExpectedUsers($type);
        foreach ($users as $user) {
            $connection->insert('users', $user);
        }

        $products = self::getExpectedProducts($type);
        foreach ($products as $product) {
            $connection->insert('products', $product);
        }
    }

    /**
     * Get the expected user data for assertions.
     *
     * @return array<int, array{id: int, name: string, email: string}>
     */
    public static function getExpectedUsers(string $type): array
    {
        return match ($type) {
            'pdo_sqlite' => [
                ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
                ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
                ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com'],
            ],
            'pdo_mysql' => [
                ['id' => 1, 'name' => 'Diana', 'email' => 'diana@example.com'],
                ['id' => 2, 'name' => 'Eve', 'email' => 'eve@example.com'],
                ['id' => 3, 'name' => 'Frank', 'email' => 'frank@example.com'],
            ],
            'pdo_pgsql' => [
                ['id' => 1, 'name' => 'Grace', 'email' => 'grace@example.com'],
                ['id' => 2, 'name' => 'Heidi', 'email' => 'heidi@example.com'],
                ['id' => 3, 'name' => 'Ivan', 'email' => 'ivan@example.com'],
            ],
            'pdo_sqlsrv' => [
                ['id' => 1, 'name' => 'Judy', 'email' => 'judy@example.com'],
                ['id' => 2, 'name' => 'Karl', 'email' => 'karl@example.com'],
                ['id' => 3, 'name' => 'Laura', 'email' => 'laura@example.com'],
            ],
            default => throw new \RuntimeException(\sprintf('Unknown database type: %s', $type)),
        };
    }

    /**
     * Get the expected product data for assertions.
     *
     * @return array<int, array{id: int, name: string, price: float}>
     */
    public static function getExpectedProducts(string $type): array
    {
        return match ($type) {
            'pdo_sqlite' => [
                ['id' => 1, 'name' => 'Widget-S', 'price' => 19.99],
                ['id' => 2, 'name' => 'Gadget-S', 'price' => 29.99],
            ],
            'pdo_mysql' => [
                ['id' => 1, 'name' => 'Widget-M', 'price' => 19.99],
                ['id' => 2, 'name' => 'Gadget-M', 'price' => 29.99],
            ],
            'pdo_pgsql' => [
                ['id' => 1, 'name' => 'Widget-P', 'price' => 19.99],
                ['id' => 2, 'name' => 'Gadget-P', 'price' => 29.99],
            ],
            'pdo_sqlsrv' => [
                ['id' => 1, 'name' => 'Widget-Q', 'price' => 19.99],
                ['id' => 2, 'name' => 'Gadget-Q', 'price' => 29.99],
            ],
            default => throw new \RuntimeException(\sprintf('Unknown database type: %s', $type)),
        };
    }

    /**
     * Detect the database type from the connection.
     */
    private static function detectDatabaseType(Connection $connection): string
    {
        /** @var array{driver?: string, url?: string} $params */
        $params = $connection->getParams();

        // Check for driver in params
        if (isset($params['driver']) && \is_string($params['driver'])) {
            return $params['driver'];
        }

        // Check URL for driver type
        if (isset($params['url']) && \is_string($params['url'])) {
            if (str_starts_with($params['url'], 'mysql://') || str_starts_with($params['url'], 'pdo-mysql://')) {
                return 'pdo_mysql';
            }
            if (str_starts_with($params['url'], 'postgresql://') || str_starts_with($params['url'], 'postgres://') || str_starts_with($params['url'], 'pdo-pgsql://')) {
                return 'pdo_pgsql';
            }
            if (str_starts_with($params['url'], 'sqlite://') || str_starts_with($params['url'], 'pdo-sqlite://')) {
                return 'pdo_sqlite';
            }
            if (str_starts_with($params['url'], 'sqlsrv://') || str_starts_with($params['url'], 'pdo-sqlsrv://')) {
                return 'pdo_sqlsrv';
            }
        }

        throw new \RuntimeException('Unable to detect database type from connection');
    }

    /**
     * Create the users table with database-specific SQL.
     */
    private static function createUsersTable(Connection $connection, string $type): void
    {
        $sql = match ($type) {
            'pdo_sqlite' => '
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    email TEXT NOT NULL
                )
            ',
            'pdo_mysql' => '
                CREATE TABLE users (
                    id INT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ',
            'pdo_pgsql' => '
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL
                )
            ',
            'pdo_sqlsrv' => '
                CREATE TABLE users (
                    id INT PRIMARY KEY,
                    name NVARCHAR(255) NOT NULL,
                    email NVARCHAR(255) NOT NULL
                )
            ',
            default => throw new \RuntimeException(\sprintf('Unknown database type: %s', $type)),
        };

        $connection->executeStatement($sql);
    }

    /**
     * Create the products table with database-specific SQL.
     */
    private static function createProductsTable(Connection $connection, string $type): void
    {
        $sql = match ($type) {
            'pdo_sqlite' => '
                CREATE TABLE products (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    price REAL NOT NULL
                )
            ',
            'pdo_mysql' => '
                CREATE TABLE products (
                    id INT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    price DECIMAL(10, 2) NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ',
            'pdo_pgsql' => '
                CREATE TABLE products (
                    id INTEGER PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    price NUMERIC(10, 2) NOT NULL
                )
            ',
            'pdo_sqlsrv' => '
                CREATE TABLE products (
                    id INT PRIMARY KEY,
                    name NVARCHAR(255) NOT NULL,
                    price DECIMAL(10, 2) NOT NULL
                )
            ',
            default => throw new \RuntimeException(\sprintf('Unknown database type: %s', $type)),
        };

        $connection->executeStatement($sql);
    }
}
