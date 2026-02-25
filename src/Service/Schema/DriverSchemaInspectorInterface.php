<?php

declare(strict_types=1);

namespace App\Service\Schema;

use Doctrine\DBAL\Connection;

interface DriverSchemaInspectorInterface
{
    /** @return list<string> */
    public function getStoredProcedures(Connection $connection): array;

    /** @return list<string> */
    public function getFunctions(Connection $connection): array;

    /** @return list<string> */
    public function getTriggers(Connection $connection): array;

    /** @return array<int, array<string, mixed>> */
    public function getTableTriggers(Connection $connection, string $tableName): array;

    /** @return array<int, array<string, mixed>> */
    public function getTableCheckConstraints(Connection $connection, string $tableName): array;
}
