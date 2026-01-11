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
     * Create the users table schema.
     */
    public static function setupSchema(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL
            )
        ');
    }

    /**
     * Load sample user data into the database.
     */
    public static function loadFixtures(Connection $connection): void
    {
        $users = self::getExpectedUsers();

        foreach ($users as $user) {
            $connection->insert('users', $user);
        }
    }

    /**
     * Get the expected user data for assertions.
     *
     * @return array<int, array{id: int, name: string, email: string}>
     */
    public static function getExpectedUsers(): array
    {
        return [
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
            ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com'],
        ];
    }
}
