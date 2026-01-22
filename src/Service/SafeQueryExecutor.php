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
     * @param Connection $conn The database connection
     * @param string     $sql  The SQL query to execute
     *
     * @return array<int, array<string, mixed>> Query results
     *
     * @throws Exception
     */
    public function execute(Connection $conn, string $sql): array
    {
        // Layer 1: Validate SQL keywords
        $this->validateSql($sql);

        // Layer 3: Sandboxed execution with rollback
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

    /**
     * Validate that SQL does not contain forbidden keywords.
     *
     * @param string $sql The SQL query to validate
     *
     * @throws \RuntimeException If forbidden keywords are detected
     */
    private function validateSql(string $sql): void
    {
        // Normalize SQL for inspection (remove comments, extra whitespace)
        $sqlForInspection = $this->normalizeSql($sql);

        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            // Match whole words only using word boundaries (\b)
            if (preg_match('/\b'.$keyword.'\b/i', $sqlForInspection)) {
                throw new \RuntimeException(\sprintf('Security violation: Keyword "%s" is not allowed in read-only mode.', $keyword));
            }
        }
    }

    /**
     * Normalize SQL by removing comments and extra whitespace.
     *
     * @param string $sql The SQL query to normalize
     *
     * @return string Normalized SQL
     */
    private function normalizeSql(string $sql): string
    {
        // Remove single-line comments (-- ...)
        $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;

        // Remove multi-line comments (/* ... */)
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;

        // Collapse whitespace
        return preg_replace('/\s+/', ' ', $sql) ?? $sql;
    }
}
