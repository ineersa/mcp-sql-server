# Add MCP Resources Capability

Add resources capability to the MCP server so LLMs can discover database tables and their structure (CREATE TABLE syntax).

## User Review Required

> [!IMPORTANT]
> **URI Scheme Design**: Resources will use a `db://` scheme with the pattern:
>
> - `db://{connection}/{table}` — for reading individual table schema
>
> Alternative considered: `mysql://` or `database://` schemes, but `db://` is shorter and database-agnostic.

> [!NOTE]
> **Resource Discovery Strategy**: MCP supports two approaches:
>
> 1. **Static Resources** — Registered individually for each table (would require loading all tables at startup)
> 2. **Resource Templates** — A URI pattern like `db://{connection}/{table}` that matches dynamically
>
> **Recommendation**: Use **Resource Templates** since tables are dynamic and we don't want to enumerate all tables upfront.

---

## Proposed Changes

### Core Service

#### [MODIFY] [DoctrineConfigLoader.php](file:///home/ineersa/mcp-servers/mysql-server/src/Service/DoctrineConfigLoader.php)

Add methods to retrieve table information from connections:

```php
/**
 * Get all table names for a connection.
 * @return string[]
 */
public function getTableNames(string $connectionName): array

/**
 * Get CREATE TABLE syntax for a specific table.
 */
public function getCreateTableSql(string $connectionName, string $tableName): string
```

---

### New Resource Handler

#### [NEW] [TableResource.php](file:///home/ineersa/mcp-servers/mysql-server/src/Resources/TableResource.php)

Create a new resource handler class following MCP SDK patterns:

```php
<?php

declare(strict_types=1);

namespace App\Resources;

use App\Service\DoctrineConfigLoader;

final class TableResource
{
    public const string URI_TEMPLATE = 'db://{connection}/{table}';
    public const string NAME = 'table';
    public const string DESCRIPTION = 'Database table schema (CREATE TABLE syntax)';

    public function __construct(
        private DoctrineConfigLoader $doctrineConfigLoader,
    ) {}

    public function __invoke(string $connection, string $table): string
    {
        return $this->doctrineConfigLoader->getCreateTableSql($connection, $table);
    }
}
```

---

### Registration

#### [MODIFY] [DatabaseMcpCommand.php](file:///home/ineersa/mcp-servers/mysql-server/src/Command/DatabaseMcpCommand.php)

Register the resource template with the MCP server builder:

```diff
+use App\Resources\TableResource;

 $server = Server::builder()
     ->setServerInfo(...)
     ->setLogger(...)
     ->setContainer(...)
     ->addTool(...)
+    ->addResourceTemplate(
+        TableResource::class,
+        TableResource::URI_TEMPLATE,
+        TableResource::NAME,
+        TableResource::DESCRIPTION,
+        mimeType: 'text/plain',
+    )
     ->build();
```

---

### Test Updates

#### [MODIFY] [resources_templates_list.json](file:///home/ineersa/mcp-servers/mysql-server/tests/__snapshots__/resources_templates_list.json)

Update snapshot to include the new resource template:

```json
{
    "resourceTemplates": [
        {
            "uriTemplate": "db://{connection}/{table}",
            "name": "table",
            "description": "Database table schema (CREATE TABLE syntax)",
            "mimeType": "text/plain"
        }
    ]
}
```

---

## Verification Plan

### Automated Tests

1. **Run existing test suite** to ensure no regressions:

    ```bash
    composer tests
    ```

2. **Add integration test** for resource reading in `tests/Inspector/`:
    - Create `TableResourceTest.php` to test reading table schema via MCP protocol
    - Test valid connection/table combinations
    - Test error handling for invalid connections or tables

## MCP Resources Feature Walkthrough

## Summary

Added MCP resources capability using resource template patterns:

- `db://{connection}` — List available tables in a database connection
- `db://{connection}/{table}` — Get CREATE TABLE syntax for a specific table

## Changes Made

### New Files

- [ConnectionResource.php](file:///home/ineersa/mcp-servers/mysql-server/src/Resources/ConnectionResource.php) — Resource handler that lists available tables
- [TableResource.php](file:///home/ineersa/mcp-servers/mysql-server/src/Resources/TableResource.php) — Resource handler that returns CREATE TABLE syntax

### Modified Files

- [DoctrineConfigLoader.php](file:///home/ineersa/mcp-servers/mysql-server/src/Service/DoctrineConfigLoader.php) — Added `getTableNames()` and `getCreateTableSql()` methods

render_diffs(file:///home/ineersa/mcp-servers/mysql-server/src/Service/DoctrineConfigLoader.php)

- [DatabaseMcpCommand.php](file:///home/ineersa/mcp-servers/mysql-server/src/Command/DatabaseMcpCommand.php) — Registered resource templates

render_diffs(file:///home/ineersa/mcp-servers/mysql-server/src/Command/DatabaseMcpCommand.php)

## Verification

| Check                                | Result          |
| ------------------------------------ | --------------- |
| Code style (`composer cs-fix`)       | ✅ Passed       |
| Static analysis (`composer phpstan`) | ✅ Passed       |
| Tests (`composer tests`)             | ✅ 19/19 passed |

## Usage

LLMs can now:

1. **List resource templates** — Discovers `db://{connection}` and `db://{connection}/{table}` patterns
2. **List tables in a connection** — Request `db://products` to get a list of available tables
3. **Read a table schema** — Request `db://products/users` to get the CREATE TABLE syntax for the `users` table
