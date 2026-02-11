<?php

declare(strict_types=1);

namespace App\Resources;

use App\Service\DoctrineConfigLoader;
use Psr\Log\LoggerInterface;

final class TableResource
{
    public const string URI_TEMPLATE = 'db://{connection}/{table}';
    public const string NAME = 'table';
    public const string DESCRIPTION = 'Action: Read this after db://{connection} to inspect exact table schema (CREATE TABLE syntax) before writing SQL. Use it to confirm column names, types, and constraints.';

    public function __construct(
        private DoctrineConfigLoader $doctrineConfigLoader,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $connection, string $table): string
    {
        try {
            return $this->doctrineConfigLoader->getCreateTableSql($connection, $table);
        } catch (\Throwable $e) {
            $this->logger->error('Table resource read failed', [
                'connection' => $connection,
                'table' => $table,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
