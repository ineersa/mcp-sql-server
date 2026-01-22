<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

final class SafeQueryExecutor
{
    /**
     * Forbidden SQL keywords that indicate write operations or transaction control.
     *
     * @var array<string>
     */
    private const FORBIDDEN_KEYWORDS = [
        'COMMIT',
        'ROLLBACK',
        'TRANSACTION',
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'ALTER',
        'CREATE',
        'TRUNCATE',
        'EXEC',
        'EXECUTE',
        'MERGE',
        'INTO',
        'GRANT',
        'REVOKE',
    ];

    /**
     * Execute a SQL query safely with three layers of protection:
     * 1. SQL keyword validation
     * 2. Platform SET commands (configured via middleware)
     * 3. Sandboxed execution with automatic rollback.
     *
     * @return array<int, array<string, mixed>> Query results
     *
     * @throws Exception
     */
    public function execute(Connection $conn, string $sql): array
    {
        $this->validateSql($sql);

        // This catches any "logic writes" (e.g., SELECT triggering side-effect functions)
        $conn->beginTransaction();
        try {
            $stmt = $conn->executeQuery($sql);
            $results = $stmt->fetchAllAssociative();
        } finally {
            // Always rollback, even on success
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
        }

        return $results;
    }

    private function validateSql(string $sql): void
    {
        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            // Match whole words only using word boundaries (\b)
            if (preg_match('/\b'.$keyword.'\b/i', $sql)) {
                throw new \RuntimeException(\sprintf('Security violation: Keyword "%s" is not allowed in read-only mode.', $keyword));
            }
        }
    }
}
