<?php

declare(strict_types=1);

namespace App\Resources;

use App\Service\DoctrineConfigLoader;
use HelgeSverre\Toon\Toon;

final class ConnectionResource
{
    public const string URI_TEMPLATE = 'db://{connection}';
    public const string NAME = 'connection';
    public const string DESCRIPTION = 'CRITICAL: Mapping of all tables in this database connection. Read this first before querying to avoid table-not-found errors, then read db://{connection}/{table} for schema details.';

    public function __construct(
        private DoctrineConfigLoader $doctrineConfigLoader,
    ) {
    }

    public function __invoke(string $connection): string
    {
        $tables = $this->doctrineConfigLoader->getTableNames($connection);

        return Toon::encode($tables);
    }
}
