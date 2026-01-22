<?php

declare(strict_types=1);

namespace App\ReadOnly;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

final class ReadOnlyConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $wrappedConnection)
    {
        parent::__construct($wrappedConnection);
    }
}
