<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

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
            if (!ConnectionFailure::matches($exception)) {
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
}
