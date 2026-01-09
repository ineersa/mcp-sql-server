Perfect! Let me update the plan based on your clarifications:

## Refined Architecture Plan

### 1. Create `DoctrineConfigLoader` Service
**Location:** `src/Service/DoctrineConfigLoader.php`

**Responsibilities:**
- Read `DATABASE_CONFIG_FILE` environment variable
- Validate the file exists and is readable
- Parse YAML using Symfony's Yaml component
- Support environment variable substitution (`%env(VAR)%`)
- Extract Doctrine DBAL connection configurations
- Support both URL format and individual connection parameters (host, dbname, user, password, driverOptions, ApplicationIntent, etc.)
- Validate that all connections can connect to their databases on initialization
- Store connection metadata (names, database types, versions)
- Provide methods:
    - `getConnectionNames(): array` - returns list of connection names
    - `getConnectionType(string $name): ?string` - returns database type (mysql, postgresql, sqlsrv, etc.)
    - `getConnectionVersion(string $name): ?string` - returns server version if specified
    - `getConnection(string $name): \Doctrine\DBAL\Connection` - returns actual DBAL connection
    - `getAllConnections(): array` - returns all configured connections

**Implementation details:**
- Use `Symfony\Component\Yaml\Yaml` to parse the config file
- Use `Symfony\Component\DependencyInjection\EnvVarProcessorInterface` or manual env var substitution for `%env(VAR)%`
- Parse `doctrine.dbal.<connection_name>` structure
- Support both single connection (top-level `url`) and multiple connections (`connections:` key)
- Create connections using `Doctrine\DBAL\DriverManager::getConnection()`
- Test each connection with a simple query (e.g., `SELECT 1`) to validate connectivity
- Throw descriptive exceptions if:
    - Config file doesn't exist
    - YAML is invalid
    - Required env vars are missing
    - Connections fail to connect
    - No connections are defined

### 2. Environment Configuration Changes

**In `.env`:**
- Remove `DATABASE_URL` variable
- Add `DATABASE_CONFIG_FILE=/path/to/doctrine.yaml`

**Keep for documentation/example:**
- `.env.example` with sample config
- Optionally add a `config/doctrine.yaml.example` showing expected format

### 3. Remove Bundle Configuration

**Action:** Disable `config/packages/doctrine.yaml`
- Delete or rename to `.yaml.disabled` to prevent Doctrine Bundle from auto-configuring
- This ensures we have full control over connection management

## Enhanced Config Format Examples

### Multiple connections with full parameters:
```yaml
doctrine:
    dbal:
        default_connection: primary
        connections:
            primary:
                url: 'mysql://user:pass@localhost:3306/dbname?serverVersion=8.0'
            secondary:
                driver: 'sqlsrv'
                host: 'server.database.windows.net'
                dbname: 'mydb'
                user: '%env(DB_USER)%'
                password: '%env(DB_PASSWORD)%'
                version: '16.0'
                applicationIntent: 'ReadOnly'
                encrypt: false
                trustServerCertificate: true
```

### Single connection with URL:
```yaml
doctrine:
    dbal:
        url: 'postgresql://user:pass@localhost:5432/dbname?serverVersion=16'
```

## Implementation Steps

1. Create `DoctrineConfigLoader` service with:
    - Environment variable reading
    - YAML parsing with env var substitution
    - Connection parameter extraction (URL + individual params)
    - Connection creation and validation
    - Getter methods for connection metadata

2. Update `.env`:
    - Remove `DATABASE_URL`
    - Add `DATABASE_CONFIG_FILE`

3. Remove/disable `config/packages/doctrine.yaml`

4. Register the service in DI container (autowire should handle this)

## Testing Considerations

- Test with invalid file paths
- Test with malformed YAML
- Test with missing env variables
- Test with invalid connection parameters
- Test connection validation failures
- Test with various database types (MySQL, PostgreSQL, SQL Server)
- Test with both URL and individual parameter formats

The scope is now focused solely on the `DoctrineConfigLoader` service. QueryTool integration and usage will be handled in a future feature.
