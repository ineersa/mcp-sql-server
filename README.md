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
- [PII Detection & Redaction](#pii-detection--redaction)
    - [Enabling PII Protection](#enabling-pii-protection)
    - [PII Discovery Command](#pii-discovery-command)
    - [Model Setup](#model-setup)
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
git clone https://github.com/ineersa/mcp-sql-server.git
cd mcp-sql-server
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

            # MySQL with URL/DSN format and PII redaction enabled
            products:
                url: "mysql://user:password@127.0.0.1:3306/mydb?serverVersion=8.0&charset=utf8mb4"
                pii_enabled: true # Enable PII redaction for query results

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

# Optional: PII configuration for GLiNER model (required if any connection has pii_enabled: true)
pii:
    tokenizer_path: "models/tokenizer.json"
    model_path: "models/model.onnx"
    threshold: 0.9 # Confidence threshold (0.0-1.0)
    # Optional: Limit detection to specific entity types (uses all 64 types if omitted)
    # labels:
    #     - email
    #     - phone_number
    #     - ssn
    #     - credit_debit_card
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

## PII Detection & Redaction

This server includes built-in PII (Personally Identifiable Information) detection and redaction using the [GLiNER-PII ONNX model](https://huggingface.co/ineersa/gliner-PII-onnx) via the native [gliner-rs-php](https://github.com/ineersa/gliner-rs-php) extension.

### Enabling PII Protection

PII redaction is configured per-connection. When enabled, query results are automatically scanned and sensitive data is replaced with `[REDACTED_type]` markers (e.g., `[REDACTED_email]`, `[REDACTED_ssn]`).

**1. Enable on specific connections** in your `databases.yaml`:

```yaml
doctrine:
    dbal:
        connections:
            production:
                url: "mysql://..."
                pii_enabled: true # Redact PII in query results
            development:
                url: "mysql://..."
                # pii_enabled defaults to false
```

**2. Add PII configuration** (required if any connection has `pii_enabled: true`):

```yaml
pii:
    tokenizer_path: "models/tokenizer.json"
    model_path: "models/model.onnx"
    threshold: 0.9 # Confidence threshold (0.0-1.0)
    # Optional: Limit to specific entity types for better performance
    labels:
        - email
        - phone_number
        - ssn
        - credit_debit_card
```

### PII Discovery Command

The `pii:discover` command scans your database tables to identify columns containing PII:

```bash
# Scan all tables in a connection
php bin/console pii:discover --connection=production

# Scan specific tables
php bin/console pii:discover -c production --tables=users,customers

# Customize sample size and confidence threshold
php bin/console pii:discover -c production -s 100 --confidence-threshold=0.8
```

**Command Options:**

| Option                   | Description                                          |
| ------------------------ | ---------------------------------------------------- |
| `-c, --connection`       | Database connection name to scan (required)          |
| `-t, --tables`           | Comma-separated list of tables (default: all tables) |
| `-s, --sample-size`      | Rows to sample per table (default: 50)               |
| `--confidence-threshold` | Minimum score to flag as PII (default: 0.9)          |

### Model Setup

**Using Docker (included automatically):**

The Docker image includes the GLiNER PHP extension. You only need to mount the model files:

```yaml
# docker-compose.yaml
services:
    database-mcp:
        volumes:
            - ./models:/app/models:ro # Mount GLiNER models
```

Download model files from [ineersa/gliner-PII-onnx](https://huggingface.co/ineersa/gliner-PII-onnx):

- `tokenizer.json` (~8MB)
- `model.onnx` (~1.8GB)

**Manual installation:**

Install the [gliner-rs-php](https://github.com/ineersa/gliner-rs-php) extension:

```bash
curl -fsSL -o gliner.tar.gz \
    https://github.com/ineersa/gliner-rs-php/releases/download/0.0.6/gliner-rs-php-0.0.6-linux-x86_64.tar.gz
tar -xzf gliner.tar.gz
cp libgliner_rs_php.so /usr/local/lib/php/extensions/
echo "extension=/usr/local/lib/php/extensions/libgliner_rs_php.so" > /usr/local/etc/php/conf.d/gliner.ini
```

### Supported PII Types

The system detects **64 entity types** across 8 categories:

| Category              | Entity Types                                                                                                                                                                                   |
| --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Personal**          | `first_name`, `last_name`, `name`, `date_of_birth`, `age`, `gender`, `sexuality`, `race_ethnicity`, `religious_belief`, `political_view`, `occupation`, `employment_status`, `education_level` |
| **Contact**           | `email`, `phone_number`, `street_address`, `city`, `county`, `state`, `country`, `coordinate`, `zip_code`, `po_box`                                                                            |
| **Financial**         | `credit_debit_card`, `cvv`, `bank_routing_number`, `account_number`, `iban`, `swift_bic`, `pin`, `ssn`, `tax_id`, `ein`                                                                        |
| **Government**        | `passport_number`, `driver_license`, `license_plate`, `national_id`, `voter_id`                                                                                                                |
| **Digital/Technical** | `ipv4`, `ipv6`, `mac_address`, `url`, `user_name`, `password`, `device_identifier`, `imei`, `serial_number`, `api_key`, `secret_key`                                                           |
| **Healthcare/PHI**    | `medical_record_number`, `health_plan_beneficiary_number`, `blood_type`, `biometric_identifier`, `health_condition`, `medication`, `insurance_policy_number`                                   |
| **Temporal**          | `date`, `time`, `date_time`                                                                                                                                                                    |
| **Organization**      | `company_name`, `employee_id`, `customer_id`, `certificate_license_number`, `vehicle_identifier`                                                                                               |

> **Performance Tip:** Limit the `labels` config to only the entity types you need. Fewer labels = faster inference.

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
git clone https://github.com/ineersa/mcp-sql-server.git
cd mcp-sql-server
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
├── Command/        # Symfony console commands (including pii:discover)
├── Enum/           # PII entity types and groups
├── ReadOnly/       # DBAL middleware for read-only enforcement
├── Service/        # Core services (including PIIAnalyzerService)
└── Tools/          # MCP tool implementations
stubs/              # PHP extension stubs for IDE support
tests/              # PHPUnit test suites
config/             # Symfony configuration
```

---

## License

MIT License — see [LICENSE](LICENSE) for details.
