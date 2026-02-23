<?php

declare(strict_types=1);

namespace App\Resources;

use App\Service\DatabaseSchemaService;
use App\Service\DoctrineConfigLoader;
use HelgeSverre\Toon\Toon;

final class RoutinesResource
{
    public const string URI_TEMPLATE = 'db://{connection}/routines';
    public const string NAME = 'routines';
    public const string DESCRIPTION = 'List all stored procedures and functions available on the particular connection.';

    public function __construct(
        private DatabaseSchemaService $databaseSchemaService,
        private DoctrineConfigLoader $doctrineConfigLoader,
    ) {
    }

    public function __invoke(string $connection): string
    {
        $conn = $this->doctrineConfigLoader->getConnection($connection);
        $routines = $this->databaseSchemaService->getRoutinesList($conn);

        return Toon::encode($routines);
    }
}
