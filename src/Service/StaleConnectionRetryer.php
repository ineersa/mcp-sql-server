<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;

final class StaleConnectionRetryer
{
    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public static function execute(Connection $connection, \Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $exception) {
            if (!self::isStaleConnection($exception)) {
                throw $exception;
            }

            try {
                $connection->close();
            } catch (\Throwable) {
                // Keep the database error that caused recovery.
            }

            return $operation();
        }
    }

    private static function isStaleConnection(\Throwable $exception): bool
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof ConnectionException
                || $current instanceof DriverException && str_starts_with($current->getSQLState() ?? '', '08')
                || $current instanceof \Doctrine\DBAL\Driver\Exception && str_starts_with($current->getSQLState() ?? '', '08')
                || str_contains(strtolower($current->getMessage()), 'ssl syscall error')
                || str_contains(strtolower($current->getMessage()), 'eof detected')
                || str_contains(strtolower($current->getMessage()), 'no connection to the server')
                || str_contains(strtolower($current->getMessage()), 'server has gone away')
                || str_contains(strtolower($current->getMessage()), 'lost connection')
                || str_contains(strtolower($current->getMessage()), 'communication link failure')) {
                return true;
            }
        }

        return false;
    }
}
