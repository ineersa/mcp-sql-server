<?php

declare(strict_types=1);

namespace App\Tools;

final class QueryTool
{
    public const string NAME = 'query';
    public const string TITLE = 'Query database';
    public const string DESCRIPTION = 'Runs SQL query against chosen database connection.';

    public function __construct()
    {

    }

    public function __invoke(
        string $connection,
        string $query,
    ) {

    }
}
