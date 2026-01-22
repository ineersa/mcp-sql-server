<?php

declare(strict_types=1);

namespace App\ReadOnly;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

final class ReadOnlyMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new ReadOnlyDriver($driver);
    }
}
