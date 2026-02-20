<?php

declare(strict_types=1);

namespace App\Resources;

use App\Service\DatabaseSchemaService;
use App\Service\DoctrineConfigLoader;
use HelgeSverre\Toon\Toon;

final class ViewsResource
{
    public const string URI_TEMPLATE = 'db://{connection}/views';
    public const string NAME = 'views';
    public const string DESCRIPTION = 'List all database views available on the particular connection.';

    public function __construct(
        private DatabaseSchemaService $databaseSchemaService,
        private DoctrineConfigLoader $doctrineConfigLoader,
    ) {
    }

    public function __invoke(string $connection): string
    {
        $conn = $this->doctrineConfigLoader->getConnection($connection);
        $views = $this->databaseSchemaService->getViewsList($conn);

        return Toon::encode($views);
    }
}
