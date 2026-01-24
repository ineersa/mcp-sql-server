# Database MCP Server

A PHP/Symfony implementation of a [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) server for executing **read-only** SQL queries against multiple databases.

## Configuration

### Environment Variables

| Variable               | Default                 | Description                                    |
| ---------------------- | ----------------------- | ---------------------------------------------- |
| `DATABASE_CONFIG_FILE` | **required**            | Path to the database configuration YAML file   |
| `APP_ENV`              | `prod`                  | Application environment                        |
| `APP_DEBUG`            | `false`                 | Enable debug mode                              |
| `LOG_LEVEL`            | `warning`               | Log level: `debug`, `info`, `warning`, `error` |
| `APP_LOG_DIR`          | `/tmp/database-mcp/log` | Directory for log files                        |

### Database Configuration File

Database connections are defined in a YAML file.

The file path is specified via the `DATABASE_CONFIG_FILE` environment variable.

File configuration follows `doctrine.yaml` Doctrine configuration standarts and supports DSN and environment variables injection.

#### Multiple Named Connections

Example configuration with various database types and configuration methods:

```yaml
doctrine:
    dbal:
        connections:
            # SQLite with explicit path
            local:
                driver: "pdo_sqlite"
                path: "var/test.sqlite"

            # MySQL with URL/DSN format
            products:
                url: "mysql://user:password@127.0.0.1:3306/mydb?serverVersion=8.0&charset=utf8mb4"

            # PostgreSQL with environment variable
            users:
                url: "%env(POSTGRES_DSN)%"

            # SQL Server with explicit parameters and driver options
            analytics:
                driver: "pdo_sqlsrv"
                host: "127.0.0.1"
                port: 1433
                dbname: "analytics"
                user: "sa"
                password: "MyPassword123"
                serverVersion: "2019"
                # Use 'options' (Doctrine standard) or 'driverOptions' (alias)
                options:
                    TrustServerCertificate: "yes"
```

**Key Features:**

- **URL/DSN Format**: Use `url` for connection strings (supports `%env(VAR)%` placeholders)
- **Explicit Parameters**: Specify `driver`, `host`, `port`, `dbname`, `user`, `password` individually
- **Environment Variables**: Use `%env(VAR_NAME)%` syntax for sensitive data
- **Driver Options**: Use either `options` (Doctrine standard) or `driverOptions` (both work)
- **Server Version**: Specify via `serverVersion` or `version` key

#### Supported Database Drivers

| Database      | Driver       | URL Scheme                |
| ------------- | ------------ | ------------------------- |
| MySQL/MariaDB | `pdo_mysql`  | `mysql://`, `mysql2://`   |
| PostgreSQL    | `pdo_pgsql`  | `postgres://`, `pgsql://` |
| SQLite        | `pdo_sqlite` | `sqlite://`               |
| SQL Server    | `pdo_sqlsrv` | `sqlsrv://`, `mssql://`   |

---

## Running the MCP Server

### Docker (Recommended)

The Docker image includes all database drivers and is the easiest way to run the server.

#### Usage

```bash
docker run --rm -i --network host \
    -e DATABASE_CONFIG_FILE=/config/databases.yaml \
    -e LOG_LEVEL=info \
    -e APP_LOG_DIR=/tmp/logs \
    -v /path/to/databases.yaml:/config/databases.yaml:ro \
    -v /tmp/logs:/tmp/logs \
    ineersa/database-mcp:latest
```

#### Viewing Logs

To view logs from the Docker container, mount the log directory:

```bash
docker run --rm -it \
    -v /tmp/database-mcp/log:/tmp/database-mcp/log:ro \
    --entrypoint php \
    ineersa/database-mcp:latest \
    /app/bin/console app:logs
```

### Without Docker

If you have PHP 8.4+ installed with the required database extensions:

1. Clone the repository: `git clone https://github.com/ineersa/database-mcp.git`
2. Install dependencies: `composer install --no-dev`
3. Create your `databases.yaml` configuration file
4. Run the server:

```bash
DATABASE_CONFIG_FILE=./databases.yaml ./bin/database-mcp
```

### MCP Client Configuration

Add this to your MCP client's configuration (e.g., `mcp.json` or Claude Desktop settings):

```json
{
    "database-server": {
        "command": "docker",
        "args": [
            "run",
            "--rm",
            "-i",
            "--network",
            "host",
            "--env-file",
            "/path/to/database-mcp.env",
            "-v",
            "/path/to/databases.yaml:/config/databases.yaml:ro",
            "ineersa/database-mcp:latest"
        ]
    }
}
```

Example `database-mcp.env` file:

```env
DATABASE_CONFIG_FILE=/config/databases.yaml
LOG_LEVEL=warning
APP_LOG_DIR=/tmp/database-mcp/log
```

For the local command:

```json
{
    "database": {
        "command": "/path/to/bin/database-mcp",
        "args": [],
        "env": {
            "DATABASE_CONFIG_FILE": "/path/to/databases.yaml",
            "LOG_LEVEL": "info",
            "APP_LOG_DIR": "/tmp/database-mcp/log"
        }
    }
}
```

### Networking Considerations

- **`--network host`**: Recommended for connecting to databases. The container shares the host's network stack and remote database connections work normally using their hostnames/IPs.

---

## Security

### Read-Only Enforcement

This MCP server enforces **read-only mode** on all database connections through:

1. **SQL Keyword Validation**: Blocks forbidden keywords (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `CREATE`, `ALTER`, `TRUNCATE`, `EXEC`, `MERGE`, `GRANT`, etc.) before execution
2. **Platform SET Commands**: Database-level read-only enforcement via session configuration:
    - **MySQL/MariaDB**: `SET SESSION transaction_read_only = 1`
    - **PostgreSQL**: `SET default_transaction_read_only = on`
    - **SQLite**: `PRAGMA query_only = ON`
    - **SQL Server**: `ApplicationIntent=ReadOnly` (see below)
3. **Sandboxed Execution**: All queries execute within a transaction that is **always rolled back**, preventing any data modifications

> **NOTE** It's always safer to just use read only permissions for database user, even with protection layer

### SQL Server Considerations

**Important**: `ApplicationIntent=ReadOnly` only works for:

- Always On Availability Groups (routing to read-only replicas)
- Azure SQL Database

For **standalone SQL Server instances**, you must configure a database user with **read-only permissions** at the infrastructure level. The SQL keyword validation and sandboxed execution layers provide additional protection, but cannot fully prevent all modifications without database-level permissions.

---

## Tools

The server exposes the following MCP tools:

### `query`

Executes read-only SQL queries against a specified database connection.

**Parameters:**

| Parameter    | Type   | Required | Description                                                     |
| ------------ | ------ | -------- | --------------------------------------------------------------- |
| `connection` | string | Yes      | Name of the database connection to use                          |
| `query`      | string | Yes      | SQL query to execute (semicolon-separated for multiple queries) |

**Behavior:**

- Only `SELECT` and other read-only operations are allowed
- Multiple queries can be executed if separated by semicolons
- Results are returned in two formats:
    - **Markdown**: Human-readable tables with row counts (in `content`)
    - **Structured JSON**: Machine-readable data with schema (in `structuredContent`)
- By default, queries should include `LIMIT 50` unless full data is needed
- The tool dynamically lists available connections and their database types/versions

**Example Request:**

```json
{
    "name": "query",
    "arguments": {
        "connection": "production",
        "query": "SELECT id, name, email FROM users LIMIT 10"
    }
}
```

**Example Response:**

```markdown
## Query 1

​`sql
SELECT id, name, email FROM users LIMIT 10
​`

### Count

10

### Rows

| id  | name  | email             |
| --- | ----- | ----------------- |
| 1   | Alice | alice@example.com |
| 2   | Bob   | bob@example.com   |

...
```

---

## Development

### Setup

```bash
git clone https://github.com/ineersa/database-mcp.git
cd database-mcp
composer install
```

### Running Locally

Use the MCP Inspector to test the server:

```bash
npx @modelcontextprotocol/inspector ./bin/database-mcp
```

Or use the Symfony console for other commands

```bash
./bin/console
```

### Code Quality

```bash
composer cs-fix
composer phpstan
composer tests
```

With Xdebug:

```bash
composer tests-xdebug
```

### Logs command

The server writes JSON logs to `APP_LOG_DIR`. Use the built-in command to view them:

```bash
# List recent logs
./bin/console app:logs

# View a specific log entry by line number
./bin/console app:logs --id=42

# Limit the number of entries shown
./bin/console app:logs --limit=50
```

### Building Docker Image

```bash
# Build the image
composer docker-build

# Or rebuild without cache
composer docker-rebuild

# Or manually
docker build -t ineersa/database-mcp:latest .
```

### Project Structure

```
src/
├── Command/        # Symfony console commands (app:logs, etc.)
├── ReadOnly/       # DBAL middleware for read-only enforcement
├── Service/        # Core services (DoctrineConfigLoader, SafeQueryExecutor)
└── Tools/          # MCP tool implementations
tests/              # PHPUnit test suites
config/             # Symfony configuration
```

---

## Plans

- PII guard for data

## License

MIT License - see [LICENSE](LICENSE) for details.
