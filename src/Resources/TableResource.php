<?php

declare(strict_types=1);

namespace App\Resources;

use App\Service\DoctrineConfigLoader;
use Psr\Log\LoggerInterface;

final class TableResource
{
    public const string URI_TEMPLATE = 'db://{connection}/{table}';
    public const string NAME = 'table';
    public const string DESCRIPTION = 'Database table schema (CREATE TABLE syntax). Use this to understand table structure before writing queries.';

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
