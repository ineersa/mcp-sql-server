<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Initializes test database with schema and fixtures.
 * Only runs in test environment when using in-memory SQLite.
 */
final class DatabaseInitializer
{
    public function __construct(
        private DoctrineConfigLoader $doctrineConfigLoader,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Initialize all in-memory databases with test schema and fixtures.
     */
    public function initializeTestDatabases(): void
    {
        foreach ($this->doctrineConfigLoader->getAllConnections() as $name => $connection) {
            if ($this->isInMemoryDatabase($connection)) {
                $this->logger->info("Initializing in-memory database: {$name}");
                $this->setupSchema($connection);
                $this->loadFixtures($connection);
            }
        }
    }

    private function isInMemoryDatabase(Connection $connection): bool
    {
        $params = $connection->getParams();

        // Log params for debugging
        $this->logger->debug('Checking database params', ['params' => $params]);

        // Check for explicit memory parameter
        if (isset($params['memory']) && true === $params['memory']) {
            return true;
        }

        // Check for :memory: in path (SQLite in-memory)
        if (isset($params['path']) && ':memory:' === $params['path']) {
            return true;
        }

        // Check for :memory: in url
        if (isset($params['url']) && str_contains($params['url'], ':memory:')) {
            return true;
        }

        return false;
    }

    private function setupSchema(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL
            )
        ');
    }

    private function loadFixtures(Connection $connection): void
    {
        $users = [
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
            ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com'],
        ];

        foreach ($users as $user) {
            $connection->insert('users', $user);
        }
    }
}
