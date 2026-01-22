# MCP SQL Server

PHP/Symfony implementation of a simple MCP server to access database and execute queries.

## Installing and running MCP

To generate binary run `./prepare_binary.sh`, it should work on Linux.

To build binary, you have to install [box-project/box](https://github.com/box-project/box/blob/main/doc/installation.md#composer)
to generate PHAR.

Thanks to amazing projects like [Static PHP](https://static-php.dev/en/) and [FrankenPHP](https://frankenphp.dev/docs/embed/) we are able to run PHP applications as a single binary now.

The easiest way is to just download binary from releases for your platform.

## Env variables

```dotenv
### Set log level, default INFO, with log action level ERROR
LOG_LEVEL=info
# Where to store logs
APP_LOG_DIR="/tmp/mcp/database-mcp/log"
```

## MCP config

**STDIO** is only supported transport for now, just add entry to `mcp.json` with a path to binary

```json
{
    "command": "./dist/database-mcp",
    "args": [],
    "env": {
        "APP_LOG_DIR": "/tmp/.symfony/database-mcp/log"
    }
}
```

You can also use `database-mcp.phar` PHAR file.
The server exposes tools: `database.query`.

If you want to use other transports use some wrapper for now, for example, [MCPO](https://github.com/open-webui/mcpo)

```bash
uvx mcpo --port 8000 -- ~/dist/database-mcp
```

## Docker

The recommended way to run the MCP server is using Docker, which includes all necessary database drivers.

### Building the Image

```bash
# Build the Docker image
composer docker-build

# Or rebuild without cache
composer docker-rebuild

# Or manually with docker
docker build -t ineersa/database-mcp:latest .
```

### Running with Docker

The container uses `--network host` to connect to databases running on the host or remote servers.

#### Basic Example

```bash
docker run --rm -i --network host \
    -e DATABASE_CONFIG_FILE=/config/databases.yaml \
    -v /path/to/your/databases.yaml:/config/databases.yaml:ro \
    ineersa/database-mcp:latest
```

#### With Individual DSN Environment Variables

```bash
docker run --rm -i --network host \
    -e MYSQL_DSN="mysql://user:pass@localhost:3306/mydb?serverVersion=8.0&charset=utf8mb4" \
    -e POSTGRES_DSN="postgres://user:pass@localhost:5432/mydb?serverVersion=16&charset=utf8" \
    -v /path/to/your/databases.yaml:/config/databases.yaml:ro \
    -e DATABASE_CONFIG_FILE=/config/databases.yaml \
    ineersa/database-mcp:latest
```

### MCP Client Configuration (Docker)

For MCP clients that support Docker containers:

```json
{
    "database-server": {
        "command": [
            "docker",
            "run",
            "--rm",
            "-i",
            "--network",
            "host",
            "-e",
            "DATABASE_CONFIG_FILE=/config/databases.yaml",
            "-v",
            "/path/to/databases.yaml:/config/databases.yaml:ro",
            "ineersa/database-mcp:latest"
        ]
    }
}
```

### Networking Considerations

- **`--network host`**: Recommended for connecting to databases on localhost or local network. The container shares the host's network stack.
- **Remote databases**: With `--network host`, remote database connections work normally using their hostnames/IPs.
- **Docker databases**: If your database runs in Docker, use `--network host` or connect both containers to the same Docker network.

### Environment Variables

| Variable               | Default                 | Description                             |
| ---------------------- | ----------------------- | --------------------------------------- |
| `APP_ENV`              | `prod`                  | Application environment                 |
| `APP_DEBUG`            | `false`                 | Enable debug mode                       |
| `LOG_LEVEL`            | `warning`               | Log level (debug, info, warning, error) |
| `APP_LOG_DIR`          | `/tmp/database-mcp/log` | Log directory                           |
| `DATABASE_CONFIG_FILE` | **required**            | Path to database config file (mount it) |

## Development

If you need to modify or want to run/debug a server locally, you should:

- `git clone` repository
- run `composer install`
- `./bin/database-mcp` contains server, while `./bin/console` holds Symfony console

To debug server you should use `npx @modelcontextprotocol/inspector`

- Lint/format: `composer cs-fix`
- Static analysis: `composer phpstan`
- Tests: `composer tests`

### Debug

```bash
php -d xdebug.mode=debug -d xdebug.client_host=127.0.0.1 -d xdebug.client_port=9003 -d xdebug.start_with_request=yes ./bin/database-mcp
```

## Security

### Read-Only Enforcement

This MCP server enforces **read-only mode** on all database connections through a **three-layer defense system**:

1. **SQL Keyword Validation**: Blocks forbidden keywords (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `CREATE`, `ALTER`, `TRUNCATE`, `EXEC`, `MERGE`, `GRANT`, etc.) before execution
2. **Platform SET Commands**: Database-level read-only enforcement via session configuration:
    - **MySQL/MariaDB**: `SET SESSION transaction_read_only = 1`
    - **PostgreSQL**: `SET default_transaction_read_only = on`
    - **SQLite**: `PRAGMA query_only = ON`
    - **SQL Server**: `ApplicationIntent=ReadOnly` (see below)
3. **Sandboxed Execution**: All queries execute within a transaction that is **always rolled back**, preventing any data modifications even if they bypass the first two layers

### SQL Server Considerations

**Important**: `ApplicationIntent=ReadOnly` only works for:

- Always On Availability Groups (routing to read-only replicas)
- Azure SQL Database

For **standalone SQL Server instances**, you must configure a database user with **read-only permissions** at the infrastructure level. The SQL keyword validation and sandboxed execution layers provide additional protection, but cannot fully prevent all modifications without database-level permissions.

Example connection string for Azure SQL or Always On:

```bash
sqlsrv://user:pass@host/database?ApplicationIntent=ReadOnly
```

## Tools definitions and logic
