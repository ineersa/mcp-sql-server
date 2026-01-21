# MCP Resources Implementation - Hybrid Approach

## Summary

Implemented a **hybrid approach** for MCP resources to balance discoverability with context efficiency:

1. **Static Resources** for database connections (`db://{connection}`)
2. **Resource Template** for table schemas (`db://{connection}/{table}`)

## Problem

Initial implementation used resource templates for both connections and tables. However, testing revealed that MCP clients (like opencode) **do not request resource templates** via `resources/templates/list`. They only request `resources/list` for static resources.

This created a discoverability problem: LLMs couldn't discover available connections or tables.

## Solution: Hybrid Approach

### Why Hybrid?

- **Connections**: Small, fixed number (typically 1-5) → Use **static resources**
    - Clients can discover via `resources/list`
    - Minimal context bloat

- **Tables**: Potentially hundreds per connection → Use **resource template**
    - Avoids bloating context with hundreds of static resources
    - LLM constructs URIs based on connection resource content
    - Template still works even if client doesn't auto-discover it

### Implementation

#### Static Resources (Connections)

```php
// Register each connection as a static resource
foreach ($connectionNames as $connectionName) {
    $builder->addResource(
        function (string $uri) use ($connectionName): string {
            $resource = new ConnectionResource($doctrineConfigLoader);
            return $resource($connectionName);
        },
        "db://{$connectionName}",  // e.g., "db://products"
        $connectionName,
        ConnectionResource::DESCRIPTION,
        mimeType: 'text/plain',
    );
}
```

**Result**: `resources/list` returns:

```json
{
  "resources": [
    {"uri": "db://local", "name": "local", ...},
    {"uri": "db://products", "name": "products", ...},
    {"uri": "db://users", "name": "users", ...},
    {"uri": "db://server", "name": "server", ...}
  ]
}
```

#### Resource Template (Tables)

```php
// Register table template for dynamic table access
$builder->addResourceTemplate(
    TableResource::class,
    TableResource::URI_TEMPLATE,  // "db://{connection}/{table}"
    TableResource::NAME,
    TableResource::DESCRIPTION,
    mimeType: 'text/plain',
);
```

**Result**: `resources/templates/list` returns:

```json
{
    "resourceTemplates": [
        {
            "uriTemplate": "db://{connection}/{table}",
            "name": "table",
            "description": "Database table schema (CREATE TABLE syntax)...",
            "mimeType": "text/plain"
        }
    ]
}
```

### User Flow

1. **LLM discovers connections**: Requests `resources/list` → sees `db://products`, `db://users`, etc.
2. **LLM reads connection resource**: Requests `db://products` → gets list of tables
3. **LLM reads table schema**: Requests `db://products/orders` → gets CREATE TABLE syntax

Even if the client doesn't discover the template, the LLM can construct table URIs from the QueryTool description which instructs it to use `db://{connection}/{table}` pattern.

## Files Modified

### Core Implementation

- **`src/Command/DatabaseMcpCommand.php`**
    - Registers static resources for each connection
    - Registers resource template for tables
    - Uses closure wrappers to invoke resource classes correctly

### Resource Handlers (Unchanged)

- **`src/Resources/ConnectionResource.php`** - Lists tables for a connection
- **`src/Resources/TableResource.php`** - Returns CREATE TABLE syntax

### Tests Updated

- **`tests/Inspector/ResourcesTest.php`** - Added test for `resources/list`
- **`tests/Inspector/__snapshots__/Resources/resources_list.json`** - Snapshot for connection resources
- **`tests/Inspector/__snapshots__/Resources/resources_templates_list.json`** - Updated to only include table template
- **`tests/Inspector/__snapshots__/QueryToolSelect/tools_list.json`** - Updated with resource usage instructions

## Verification

✅ All tests passing (29 tests, 69 assertions)
✅ Code style check passed
✅ Static analysis passed (PHPStan level 6)

## Benefits

1. **Discoverability**: Connections are discoverable via standard `resources/list`
2. **Efficiency**: Avoids context bloat from hundreds of table resources
3. **Flexibility**: Template pattern still works for table access
4. **Client Compatibility**: Works with clients that don't request templates
