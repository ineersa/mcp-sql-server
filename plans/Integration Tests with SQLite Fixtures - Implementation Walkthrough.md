# Integration Tests with SQLite Fixtures - Implementation Walkthrough

## Overview

Successfully implemented service configuration fixes and integration tests that verify actual tool call execution with SQLite fixtures containing sample data.

## Changes Made

### Service Configuration

#### [services.yaml](file:///home/ineersa/mcp-servers/mysql-server/config/services.yaml)

**Fixed autowiring issue** by making `App\Tools\` services public:

```yaml
# Make ALL services in App\Tools\ public
App\Tools\:
  resource: "../src/Tools"
  public: true
```

**Added PSR-16 cache configuration**:

```yaml
# PSR-16 cache: wrap a PSR-6 pool (ArrayAdapter)
cache.array_adapter:
  class: Symfony\Component\Cache\Adapter\ArrayAdapter
  arguments:
    $defaultLifetime: 0
    $storeSerialized: true
```

This fixed the error: `Too few arguments to function App\Tools\QueryTool::__construct()`

---

### Database Fixtures

#### [DatabaseFixtures.php](file:///home/ineersa/mcp-servers/mysql-server/tests/Fixtures/DatabaseFixtures.php) (NEW)

Created helper class with:

- **Schema**: Simple `users` table (id, name, email)
- **Fixtures**: 3 sample users (Alice, Bob, Charlie)
- **Methods**: `setupSchema()`, `loadFixtures()`, `getExpectedUsers()`

---

### Automatic Database Initialization

#### [DatabaseInitializer.php](file:///home/ineersa/mcp-servers/mysql-server/src/Service/DatabaseInitializer.php) (NEW)

Created service that automatically initializes in-memory databases with fixtures on server startup:

- **Detects in-memory databases** by checking for `memory: true` parameter or `:memory:` path
- **Sets up schema and fixtures** automatically when server starts
- **Logs initialization** for debugging

#### [DoctrineConfigLoader.php](file:///home/ineersa/mcp-servers/mysql-server/src/Service/DoctrineConfigLoader.php#L197-L205)

**Fixed parameter passing** to support SQLite-specific parameters:

```php
// SQLite specific parameters
if (isset($config['memory'])) {
    $params['memory'] = $config['memory'];
}
if (isset($config['path'])) {
    $params['path'] = $config['path'];
}
```

This was critical - without this, the `memory: true` parameter wasn't being passed to the connection, so `DatabaseInitializer` couldn't detect in-memory databases.

#### [DatabaseMcpCommand.php](file:///home/ineersa/mcp-servers/mysql-server/src/Command/DatabaseMcpCommand.php#L54-L55)

**Integrated DatabaseInitializer** into server startup:

```php
// Load and validate database connections
$this->doctrineConfigLoader->loadAndValidate();

// Initialize in-memory databases with test fixtures (if any)
$this->databaseInitializer->initializeTestDatabases();
```

---

### Integration Tests

#### [DatabaseMcpCommandTest.php](file:///home/ineersa/mcp-servers/mysql-server/tests/Inspector/DatabaseMcpCommandTest.php)

**Added query execution test** that:

1. Spawns MCP server process with test database
2. Executes actual `QueryTool` call via MCP inspector
3. Runs `SELECT * FROM users ORDER BY id`
4. Verifies response contains fixture data

**Test configuration**:

```php
'Query Execution' => [
    'method' => 'tools/call',
    'options' => [
        'toolName' => 'QueryTool',
        'toolArgs' => [
            'connection' => 'test',
            'query' => 'SELECT * FROM users ORDER BY id',
        ],
        'envVars' => [
            'DATABASE_CONFIG_FILE' => sprintf('%s/databases.test.yaml', dirname(__DIR__, 2)),
        ],
    ],
]
```

---

## Test Results

### ✅ All Tests Passing

```bash
composer tests
```

**Result**: 20 tests, 42 assertions, all passing

### ✅ Integration Test Snapshot

[tools_call.json](file:///home/ineersa/mcp-servers/mysql-server/tests/Inspector/__snapshots__/DatabaseMcpCommand/tools_call.json) shows actual fixture data:

```json
{
  "content": [
    {
      "type": "text",
      "text": "[\n    {\n        \"id\": 1,\n        \"name\": \"Alice\",\n        \"email\": \"alice@example.com\"\n    },\n    {\n        \"id\": 2,\n        \"name\": \"Bob\",\n        \"email\": \"bob@example.com\"\n    },\n    {\n        \"id\": 3,\n        \"name\": \"Charlie\",\n        \"email\": \"charlie@example.com\"\n    }\n]"
    }
  ],
  "isError": false
}
```

✅ **Verified**: Query execution returns all 3 fixture users in correct order

---

## Key Insights

### Tool Name Discovery

The MCP SDK registers tools using the **class name** by default, not the `NAME` constant. So `QueryTool` is registered as `"QueryTool"`, not `"query"`.

### In-Memory Database Lifecycle

Each test spawns a **fresh MCP server process** with a new in-memory database. The `DatabaseInitializer` service ensures fixtures are loaded automatically on server startup, making tests deterministic and isolated.

### Parameter Passing

Doctrine DBAL connection parameters must be explicitly passed through in `DoctrineConfigLoader`. The `memory: true` parameter wasn't being forwarded, which prevented detection of in-memory databases.

---

## Summary

✅ Fixed service configuration to make tools publicly accessible
✅ Created database fixtures with simple schema and sample data
✅ Implemented automatic fixture loading for in-memory databases
✅ Added integration test that executes actual SQL queries
✅ Verified response contains expected fixture data
✅ All 20 tests passing with 42 assertions
