# Repository Guidelines

## Project Structure & Module Organization
- Source: `src/` (Symfony Console app). Key areas: `Command/` (entry commands), `Tools/` (MCP tools), `Service/` (Services with actual logic). Autoload namespace: `App\\`.
- Config: `config/` (DI, logging), env: `.env`, runtime files: `var/`.
- Binaries: `bin/console` (generic) and `bin/database-mcp` (runs default `database-mcp` command).
- Tests: `tests/` (PHPUnit), vendor deps in `vendor/`. Build artifacts in `dist/`.

## Build, Test, and Development Commands
- Install: `composer install` (PHP ≥ 8.4 required).
- Run locally: `php bin/database-mcp` (default) or `php bin/console database-mcp`; you can also invoke services via the container in app code.
  - Configure via `.env` (e.g., `APP_ENV=dev`, `APP_DEBUG=1`).
- Lint/format: `composer cs-fix` (php-cs-fixer, Symfony rules).
- Static analysis: `composer phpstan` (config `phpstan.dist.neon`).
- Tests: `composer tests` (PHPUnit testdox).
- Static binary (optional): `docker build -f static-build.Dockerfile .`.

## Coding Style & Naming Conventions
- PHP strict types; 4-space indent; UTF-8; PSR-4 under `App\\`.
- Classes: StudlyCase; methods/props: camelCase; constants: UPPER_SNAKE_CASE.
- Folders: `Command/*Command.php`, `Tools/*Tool.php`, `Service/*Service.php`.
- Run `composer cs-fix`, `composer phpstan`, and `composer tests` before pushing; no mixed tabs/spaces. Keep imports ordered.
- Avoid comments unless they are necessary

## Testing Guidelines
- Framework: PHPUnit 12. Tests live in `tests/`; bootstrap at `tests/bootstrap.php`.
- Name tests `*Test.php`, mirror namespaces. Prefer small, isolated tests around Tools/Services.
- Run `composer tests` locally; keep tests green and deterministic.

## Configuration
- All services read these via the container (see `config/services.yaml`).

## Service Usage Examples


## MCP Tools

## Commit & Pull Request Guidelines
- Commits: imperative mood, concise scope (e.g., "Add SearchTool input validation"). Group related changes.
- PRs: include summary, rationale, and how to verify (commands/output). Link issues. Update docs if behavior changes.
- CI readiness: run `composer cs-fix`, `composer phpstan`, and `composer tests` before opening a PR.

## Security & Configuration Tips
- Never commit secrets. Use `.env.local` for machine-specific overrides.
- Logging via Monolog; adjust `LOG_LEVEL` and optional `APP_LOG_DIR` in `.env`.
- You can use `./bin/console app:log` to check recent logs
  ```shell
    Description:
      Pretty-print recent JSON logs from the latest log file. Shows a table by default; use --id to inspect a single entry.
    Usage:
      app:logs [options]
    Options:
      --id=ID           Log id from the last listing to pretty print
      --limit=LIMIT     How many latest log rows to show [default: "100"]
  ```
