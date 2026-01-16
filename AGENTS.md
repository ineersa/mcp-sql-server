# Repository Guidelines for Agents

This repository contains a Symfony Console application acting as a Model Context
Protocol (MCP) server for SQL databases. Follow these guidelines strictly when
modifying the codebase.

## 1. Build, Lint, and Test Commands

### Installation & Setup

- **Install Dependencies**:

    ```bash
    composer install
    ```

    _Requires PHP >= 8.4_.

- **Run the Server Locally**:

    ```bash
    php bin/database-mcp
    # or
    php bin/console database-mcp
    ```

    Configuration is handled via `.env` (copy `.env` to `.env.local` for local
    overrides).

### Quality Assurance (Run before every commit)

- **Code Style Fixer**:

    ```bash
    composer cs-fix
    ```

    This runs `php-cs-fixer` with Symfony rules and strict type enforcement.

- **Static Analysis**:

    ```bash
    composer phpstan
    ```

    Runs PHPStan at level 6. Ensure no errors remain.

- **Run All Tests**:

    ```bash
    composer tests
    ```

    Runs PHPUnit 12 with TestDox output.

### Running Specific Tests

To run a single test file or specific test case, use the PHPUnit binary directly:

- **Run a specific test file**:

    ```bash
    vendor/bin/phpunit tests/Service/MyServiceTest.php
    ```

- **Run a specific test method**:

    ```bash
    vendor/bin/phpunit --filter testMyFeature
    ```

- **Run tests with coverage (requires XDebug)**:

    ```bash
    composer coverage
    ```

## 2. Code Style & Conventions

### General Standards

- **Strict Types**: All PHP files **MUST** start with `declare(strict_types=1);`
  as the first statement after `<?php`.
- **Indentation**: Use **4 spaces** for indentation. No tabs.
- **Encoding**: UTF-8.
- **Line Length**: Soft limit of 120 characters.

### Naming Conventions

- **Classes/Interfaces/Traits**: `StudlyCase` (e.g., `DatabaseConnection`).
- **Methods/Functions**: `camelCase` (e.g., `executeParams`).
- **Variables/Properties**: `camelCase` (e.g., `$tableName`).
- **Constants**: `UPPER_SNAKE_CASE` (e.g., `DEFAULT_TIMEOUT`).
- **Namespaces**: PSR-4, starting with `App\`.
    Source: `src/` -> `App\`
    Tests: `tests/` -> `App\Tests\`

### Project Structure

- **Commands**: `src/Command/` - Entry points for CLI commands (extend
  `Symfony\Component\Console\Command\Command`).
- **Tools**: `src/Tools/` - MCP Tool implementations.
- **Services**: `src/Service/` - Core business logic and database interactions.
- **Configuration**: `config/` - Service definitions (`services.yaml`) and
  packages.

### Class Structure & Ordering

Follow the order defined in `.php-cs-fixer.dist.php`:

1. `use` statements (Trait imports)
2. `enum` cases
3. Constants (`public`, `protected`, `private`)
4. Properties (`public`, `protected`, `private`)
5. Constructor (`__construct`)
6. Destructor (`__destruct`)
7. Magic methods
8. PHPUnit methods (if a test class)
9. Methods (`public`, `protected`, `private`)

### Error Handling

- Use Symfony's Exception classes or custom exceptions where appropriate.
- **Never** silence errors with `@`.
- Use strict typing to catch type errors early.

### Dependency Injection

- Use **Constructor Injection** for all dependencies.
- Avoid pulling services from the container directly (Service Locator pattern)
  unless absolutely necessary in a factory or compiler pass.
- Services are autowired by default (`config/services.yaml`).

## 3. Testing Guidelines

- **Framework**: PHPUnit 12.
- **Location**: Tests reside in `tests/` and should mirror the `src/` directory
  structure.
- **Naming**: Test classes must end in `Test` (e.g., `DatabaseServiceTest.php`).
- **Isolation**: Tests should be isolated. Mock external dependencies (like
  database connections) where possible for unit tests.
- **Integration Tests**: Place database-dependent tests in a separate suite or
  ensure they clean up after themselves.
- **Assertions**: Use strict assertions (e.g., `assertSame` instead of
  `assertEquals`) when possible.

## 4. MCP Server Specifics

- **Tools**: Implement new tools in `src/Tools/`.
- **Registration**: Ensure new tools are registered/tagged correctly if not
  automatically discovered.
- **Input Validation**: Validate all inputs in the Tool's `__invoke` or
  execution method.

## 5. Workflow for Agents

1. **Analyze**: Understand the requirement and existing code. Run `ls -R src` or
   `grep` to find relevant files.
2. **Verify Environment**: Check `composer.json` for dependencies.
3. **Implement**: Write code following the style guidelines above.
    - _Always_ add `declare(strict_types=1);`.
    - _Always_ add return types and property types.
4. **Test**:
    - Create or update a test case in `tests/`.
    - Run the specific test using `vendor/bin/phpunit path/to/test`.
5. **Refine**:
    - Run `composer cs-fix` to format code.
    - Run `composer phpstan` to check for static analysis errors.
6. **Finalize**: Run `composer tests` to verify.

## 6. Documentation

- Keep comments minimal. Code should be self-documenting.
- Use PHPDoc (`/** ... */`) only when PHP type hints are insufficient (e.g.,
  `array<string, mixed>`).
- Update `README.md` if adding new features or changing configuration options.
