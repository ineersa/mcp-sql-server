<?php

declare(strict_types=1);

namespace App\Resources;

use App\Service\DoctrineConfigLoader;

final class ConnectionResource
{
    public const string URI_TEMPLATE = 'db://{connection}';
    public const string NAME = 'connection';
    public const string DESCRIPTION = 'List available tables in a database connection. Use this to discover tables before reading their schema.';

    public function __construct(
        private DoctrineConfigLoader $doctrineConfigLoader,
    ) {
    }

    public function __invoke(string $connection): string
    {
        $tables = $this->doctrineConfigLoader->getTableNames($connection);

        return implode("\n", $tables);
    }
}
