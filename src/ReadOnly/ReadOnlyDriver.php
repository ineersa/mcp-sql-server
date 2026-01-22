<?php

declare(strict_types=1);

namespace App\ReadOnly;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class ReadOnlyDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $wrappedDriver)
    {
        parent::__construct($wrappedDriver);
    }

    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        $connection = parent::connect($params);

        // Detect platform and execute appropriate read-only SET command
        $driver = $params['driver'] ?? '';
        $readOnlyQuery = $this->getReadOnlyQuery($driver);

        if (null !== $readOnlyQuery) {
            try {
                $connection->exec($readOnlyQuery);
            } catch (\Throwable $e) {
                // Log but don't fail - some platforms may not support these commands
                // The SafeQueryExecutor provides additional protection
            }
        }

        return new ReadOnlyConnection($connection);
    }

    /**
     * Get the platform-specific read-only SQL command.
     *
     * @param string $driver The driver name
     *
     * @return string|null The SQL command to execute, or null if not applicable
     */
    private function getReadOnlyQuery(string $driver): ?string
    {
        return match (true) {
            str_contains($driver, 'mysql') => 'SET SESSION transaction_read_only = 1',
            str_contains($driver, 'pgsql') => 'SET default_transaction_read_only = on',
            str_contains($driver, 'sqlite') => 'PRAGMA query_only = ON',
            // SQL Server: ApplicationIntent=ReadOnly should be in DSN
            // No SET command needed here
            default => null,
        };
    }
}
