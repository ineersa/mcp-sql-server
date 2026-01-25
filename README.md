# Database MCP Server

A PHP/Symfony [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) server for executing **read-only** SQL queries against multiple databases.

**Supports:** MySQL, MariaDB, PostgreSQL, SQLite, SQL Server

---

## Table of Contents

- [Quick Start](#quick-start)
- [Configuration](#configuration)
    - [Environment Variables](#environment-variables)
    - [Database Configuration File](#database-configuration-file)
- [MCP Client Setup](#mcp-client-setup)
- [Available Tools](#available-tools)
- [Security](#security)
- [Development](#development)
- [License](#license)

---

## Quick Start

### Using Docker (Recommended)

The Docker image includes all database drivers. Just 3 steps:

**1. Download the example configuration:**

```bash
curl -L https://raw.githubusercontent.com/ineersa/mcp-sql-server/refs/heads/main/docker-compose.example.yaml -o docker-compose.yaml
```

**2. Create your `databases.yaml`** with your database connections:

```yaml
doctrine:
    dbal:
        connections:
            mydb:
                url: "mysql://user:password@127.0.0.1:3306/mydb"
```

**3. Configure your MCP client** (see [MCP Client Setup](#mcp-client-setup))

That's it! Your MCP client will spawn the server automatically.

### Without Docker

Requires PHP 8.4+ with database extensions installed:

```bash
git clone https://github.com/ineersa/database-mcp.git
cd database-mcp
composer install --no-dev
```

---

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

Database connections are defined in a YAML file (path set via `DATABASE_CONFIG_FILE`).

Configuration follows Doctrine DBAL standards with DSN and environment variable support.

#### Example Configuration

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
                options:
                    TrustServerCertificate: "yes"
```

#### Supported Databases

| Database      | Driver       | URL Scheme                |
| ------------- | ------------ | ------------------------- |
| MySQL/MariaDB | `pdo_mysql`  | `mysql://`, `mysql2://`   |
| PostgreSQL    | `pdo_pgsql`  | `postgres://`, `pgsql://` |
| SQLite        | `pdo_sqlite` | `sqlite://`               |
| SQL Server    | `pdo_sqlsrv` | `sqlsrv://`, `mssql://`   |

---

## MCP Client Setup

Add this to your MCP client's configuration (e.g., `mcp.json` or Claude Desktop settings).

### Docker Compose (Recommended)

```json
{
    "database": {
        "command": "docker",
        "args": [
            "compose",
            "-f",
            "/path/to/docker-compose.yaml",
            "run",
            "--rm",
            "database-mcp"
        ]
    }
}
```

### Opencode

```json
{
    "mcp": {
        "database": {
            "type": "local",
            "command": [
                "docker",
                "compose",
                "-f",
                "/path/to/docker-compose.yaml",
                "run",
                "--rm",
                "database-mcp"
            ],
            "enabled": true
        }
    }
}
```

### Local Installation

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

### Testing Your Setup

Verify your configuration works:

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}' | \
docker compose run --rm database-mcp
```

You should see a JSON response with server capabilities.

### Viewing Logs

```bash
# View recent logs
docker compose run --rm logs

# View a specific log entry by line number
docker compose run --rm logs --id=42

# View more entries
docker compose run --rm logs --limit=100
```

### Updating

```bash
docker compose pull
```

---

## Available Tools

### `query`

Executes read-only SQL queries against a specified database connection.

**Parameters:**

| Parameter    | Type   | Required | Description                                                     |
| ------------ | ------ | -------- | --------------------------------------------------------------- |
| `connection` | string | Yes      | Name of the database connection to use                          |
| `query`      | string | Yes      | SQL query to execute (semicolon-separated for multiple queries) |

**Example:**

```json
{
    "name": "query",
    "arguments": {
        "connection": "production",
        "query": "SELECT id, name, email FROM users LIMIT 10"
    }
}
```

**Response format:**

- **Markdown**: Human-readable tables with row counts
- **Structured JSON**: Machine-readable data with schema (in `structuredContent`)

---

## Security

### Read-Only Enforcement

This server enforces **read-only mode** through multiple layers:

1. **SQL Keyword Validation** — Blocks forbidden keywords (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `CREATE`, `ALTER`, `TRUNCATE`, etc.) before execution
2. **Platform SET Commands** — Database-level read-only enforcement:
    - **MySQL/MariaDB**: `SET SESSION transaction_read_only = 1`
    - **PostgreSQL**: `SET default_transaction_read_only = on`
    - **SQLite**: `PRAGMA query_only = ON`
    - **SQL Server**: `ApplicationIntent=ReadOnly`
3. **Sandboxed Execution** — All queries run in a transaction that is **always rolled back**

> **Best Practice:** Always use a database user with read-only permissions for additional security.

### SQL Server Note

`ApplicationIntent=ReadOnly` only works for Always On Availability Groups and Azure SQL Database. For standalone instances, configure a read-only database user.

---

## Development

### Setup

```bash
git clone https://github.com/ineersa/database-mcp.git
cd database-mcp
composer install
```

### Testing with MCP Inspector

```bash
npx @modelcontextprotocol/inspector ./bin/database-mcp
```

### Code Quality

```bash
composer cs-fix    # Fix code style
composer phpstan   # Static analysis
composer tests     # Run tests
```

### Building Docker Image

```bash
composer docker-build     # Build the image
composer docker-rebuild   # Rebuild without cache
```

### Project Structure

```
src/
├── Command/        # Symfony console commands
├── ReadOnly/       # DBAL middleware for read-only enforcement
├── Service/        # Core services
└── Tools/          # MCP tool implementations
tests/              # PHPUnit test suites
config/             # Symfony configuration
```

---

## License

MIT License — see [LICENSE](LICENSE) for details.
