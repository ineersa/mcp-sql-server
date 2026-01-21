<?php

declare(strict_types=1);

namespace App\Resources;

use App\Service\DoctrineConfigLoader;

final class TableResource
{
    public const string URI_TEMPLATE = 'db://{connection}/{table}';
    public const string NAME = 'table';
    public const string DESCRIPTION = 'Database table schema (CREATE TABLE syntax). Use this to understand table structure before writing queries.';

    public function __construct(
        private DoctrineConfigLoader $doctrineConfigLoader,
    ) {
    }

    public function __invoke(string $connection, string $table): string
    {
        return $this->doctrineConfigLoader->getCreateTableSql($connection, $table);
    }
}
