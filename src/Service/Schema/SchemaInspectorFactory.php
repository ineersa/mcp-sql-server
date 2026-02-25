<?php

declare(strict_types=1);

namespace App\Service\Schema;

use Doctrine\DBAL\Connection;

final class SchemaInspectorFactory
{
    public function __construct(
        private MysqlSchemaInspector $mysqlSchemaInspector,
        private PostgreSqlSchemaInspector $postgreSqlSchemaInspector,
        private SqliteSchemaInspector $sqliteSchemaInspector,
        private SqlServerSchemaInspector $sqlServerSchemaInspector,
    ) {
    }

    public function create(Connection $connection): DriverSchemaInspectorInterface
    {
        $params = $connection->getParams();
        $driver = isset($params['driver']) && \is_string($params['driver'])
            ? strtolower($params['driver'])
            : strtolower(basename(str_replace('\\', '/', \get_class($connection->getDatabasePlatform()))));

        return match (true) {
            str_contains($driver, 'mysql'), str_contains($driver, 'mariadb') => $this->mysqlSchemaInspector,
            str_contains($driver, 'pgsql'), str_contains($driver, 'postgres') => $this->postgreSqlSchemaInspector,
            str_contains($driver, 'sqlite') => $this->sqliteSchemaInspector,
            str_contains($driver, 'sqlsrv'), str_contains($driver, 'sqlserver') => $this->sqlServerSchemaInspector,
            default => throw new \RuntimeException(\sprintf('Unsupported database driver "%s" for schema inspection.', $driver)),
        };
    }
}
