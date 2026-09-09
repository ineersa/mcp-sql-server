<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;

final class ConnectionFailure
{
    public static function matches(\Throwable $exception): bool
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
