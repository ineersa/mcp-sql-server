# Read-Only Middleware Implementation Plan

Implement a multi-layered defense to enforce read-only mode on all database connections, preventing any data or schema modifications through the MCP server.

## Defense Layers

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Layer 1: SQL Keyword Validation                  │
│  Block forbidden keywords before execution (COMMIT, INSERT, etc.)   │
├─────────────────────────────────────────────────────────────────────┤
│                    Layer 2: Platform SET Commands                   │
│  MySQL: SET SESSION transaction_read_only = 1                       │
│  PostgreSQL: SET default_transaction_read_only = on                 │
│  SQLite: PRAGMA query_only = ON                                     │
│  SQL Server: ApplicationIntent=ReadOnly (replicas/Azure)            │
├─────────────────────────────────────────────────────────────────────┤
│                    Layer 3: Sandboxed Execution                     │
│  BEGIN TRANSACTION → Execute → ROLLBACK (always)                    │
│  Catches logic writes (e.g., SELECT triggering side-effect funcs)   │
└─────────────────────────────────────────────────────────────────────┘
```

> [!WARNING]
> **SQL Server Standalone**: `ApplicationIntent=ReadOnly` only works for Always On replicas and Azure SQL. For standalone SQL Server, users must configure a read-only database user. This will be documented in README.

---

## Proposed Changes

### SafeQuery Service

#### [NEW] [SafeQueryExecutor.php](file:///home/ineersa/mcp-servers/mysql-server/src/Service/SafeQueryExecutor.php)

Service that implements all three defense layers:

```php
class SafeQueryExecutor
{
    private const FORBIDDEN_KEYWORDS = [
        'COMMIT', 'ROLLBACK', 'TRANSACTION',
        'INSERT', 'UPDATE', 'DELETE',
        'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
        'EXEC', 'EXECUTE',
        'INTO',  // SELECT INTO creates tables
        'MERGE', 'GRANT', 'REVOKE',
    ];

    public function execute(Connection $conn, string $sql): array
    {
        // Layer 1: Validate keywords
        $this->validateSql($sql);

        // Layer 3: Sandboxed execution
        $conn->beginTransaction();
        try {
            $stmt = $conn->executeQuery($sql);
            $results = $stmt->fetchAllAssociative();
        } finally {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
        }

        return $results;
    }
}
```

---

### ReadOnly Middleware (Layer 2)

#### [NEW] [ReadOnlyMiddleware.php](file:///home/ineersa/mcp-servers/mysql-server/src/ReadOnly/ReadOnlyMiddleware.php)

Entry point implementing `Doctrine\DBAL\Driver\Middleware`.

---

#### [NEW] [ReadOnlyDriver.php](file:///home/ineersa/mcp-servers/mysql-server/src/ReadOnly/ReadOnlyDriver.php)

Extends `AbstractDriverMiddleware`. Executes platform-specific SET commands after connect:

| Database      | Command                                  |
| ------------- | ---------------------------------------- |
| MySQL/MariaDB | `SET SESSION transaction_read_only = 1`  |
| PostgreSQL    | `SET default_transaction_read_only = on` |
| SQLite        | `PRAGMA query_only = ON`                 |
| SQL Server    | DSN: `ApplicationIntent=ReadOnly`        |

---

#### [NEW] [ReadOnlyConnection.php](file:///home/ineersa/mcp-servers/mysql-server/src/ReadOnly/ReadOnlyConnection.php)

Extends `AbstractConnectionMiddleware`. Wrapper for the connection (for future extensibility).

---

### Integration

#### [MODIFY] [DoctrineConfigLoader.php](file:///home/ineersa/mcp-servers/mysql-server/src/Service/DoctrineConfigLoader.php)

Add `ReadOnlyMiddleware` to connection params:

```diff
+$params['middlewares'] = [new ReadOnlyMiddleware()];
 $connection = DriverManager::getConnection($params);
```

---

#### [MODIFY] [QueryTool.php](file:///home/ineersa/mcp-servers/mysql-server/src/Tools/QueryTool.php)

Replace direct `executeQuery()` with `SafeQueryExecutor`:

```diff
-$result = $conn->executeQuery($singleQuery);
-$rows = $result->fetchAllAssociative();
+$rows = $this->safeQueryExecutor->execute($conn, $singleQuery);
```

---

### Documentation

#### [MODIFY] [README.md](file:///home/ineersa/mcp-servers/mysql-server/README.md)

Add security section:

- Document read-only enforcement
- Note that SQL Server standalone requires a read-only database user

---

### Tests

#### [NEW] [SafeQueryExecutorTest.php](file:///home/ineersa/mcp-servers/mysql-server/tests/Service/SafeQueryExecutorTest.php)

- Test forbidden keyword detection
- Test sandboxed rollback behavior

#### [NEW] [ReadOnlyIntegrationTest.php](file:///home/ineersa/mcp-servers/mysql-server/tests/ReadOnly/ReadOnlyIntegrationTest.php)

- Verify DML fails on each platform
- Verify SELECT succeeds

---

## Verification Plan

### Automated Tests

```bash
vendor/bin/phpunit tests/Service/SafeQueryExecutorTest.php
vendor/bin/phpunit tests/ReadOnly/
composer tests
```

### Manual Verification

```sql
-- Should succeed:
SELECT * FROM users LIMIT 1;

-- Should fail (Layer 1 - keyword validation):
INSERT INTO users (name) VALUES ('test');

-- Should fail (Layer 2 - platform SET):
-- (if keyword validation is bypassed somehow)
```

---

## Implementation Order

1. ✅ Create `SafeQueryExecutor` service
2. ✅ Create `ReadOnlyMiddleware`, `ReadOnlyDriver`, `ReadOnlyConnection`
3. ✅ Modify `DoctrineConfigLoader` to use middleware
4. ✅ Modify `QueryTool` to use `SafeQueryExecutor`
5. ✅ Update README with security notes
6. ✅ Create tests
7. ✅ Run verification

---

## Walkthrough

### Implementation Completed ✅

Successfully implemented the three-layer defense system for read-only enforcement.

#### Created Files

- [`SafeQueryExecutor.php`](file:///home/ineersa/mcp-servers/mysql-server/src/Service/SafeQueryExecutor.php) - SQL validation and sandboxed execution
- [`ReadOnlyMiddleware.php`](file:///home/ineersa/mcp-servers/mysql-server/src/ReadOnly/ReadOnlyMiddleware.php) - Middleware entry point
- [`ReadOnlyDriver.php`](file:///home/ineersa/mcp-servers/mysql-server/src/ReadOnly/ReadOnlyDriver.php) - Platform-specific SET commands
- [`ReadOnlyConnection.php`](file:///home/ineersa/mcp-servers/mysql-server/src/ReadOnly/ReadOnlyConnection.php) - Connection wrapper
- [`SafeQueryExecutorTest.php`](file:///home/ineersa/mcp-servers/mysql-server/tests/Service/SafeQueryExecutorTest.php) - Unit tests (20 tests)
- [`QueryToolReadOnlyTest.php`](file:///home/ineersa/mcp-servers/mysql-server/tests/Inspector/QueryToolReadOnlyTest.php) - Inspector tests (5 tests)

#### Modified Files

- [`DoctrineConfigLoader.php`](file:///home/ineersa/mcp-servers/mysql-server/src/Service/DoctrineConfigLoader.php#L194-L195) - Added middleware
- [`QueryTool.php`](file:///home/ineersa/mcp-servers/mysql-server/src/Tools/QueryTool.php#L18-L19) - Integrated SafeQueryExecutor
- [`README.md`](file:///home/ineersa/mcp-servers/mysql-server/README.md#L68-L95) - Added security documentation

#### Test Results

**All 50 tests passing ✅**

- Unit tests: 20/20 passing
    - Blocks all 16 forbidden keywords
    - Always rolls back transactions
    - Strips comments before validation

- Inspector tests: 5/5 passing
    - SELECT queries succeed
    - INSERT/UPDATE/DELETE/DROP blocked with security violations

- Code quality: ✅
    - php-cs-fixer: 0 issues
    - PHPStan level 6: 0 errors

#### Example Security Response

```json
{
    "content": [
        {
            "type": "text",
            "text": "Error: Security violation: Keyword \"INSERT\" is not allowed in read-only mode."
        }
    ],
    "isError": true
}
```

#### Platform Coverage

| Platform      | Layer 1 (Validation) | Layer 2 (SET Command)                                  | Layer 3 (Rollback) |
| ------------- | -------------------- | ------------------------------------------------------ | ------------------ |
| MySQL/MariaDB | ✅                   | ✅ `SET SESSION transaction_read_only = 1`             | ✅                 |
| PostgreSQL    | ✅                   | ✅ `SET default_transaction_read_only = on`            | ✅                 |
| SQLite        | ✅                   | ✅ `PRAGMA query_only = ON`                            | ✅                 |
| SQL Server    | ✅                   | ⚠️ `ApplicationIntent=ReadOnly` (Azure/Always On only) | ✅                 |

> **Note**: SQL Server standalone requires read-only database user configuration at infrastructure level.
