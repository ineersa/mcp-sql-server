# Database MCP Server - Production Dockerfile
# Supports: MySQL, PostgreSQL, SQLite, SQL Server
# Includes GLiNER PHP extension for PII detection

FROM php:8.4-cli-bookworm

LABEL maintainer="Illia Vasylevskyi <ineersa@gmail.com>"
LABEL description="MCP server implementation to work with SQL databases"
LABEL version="0.0.2"

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    # SSL certificates for HTTPS
    ca-certificates \
    # Required for pdo_pgsql
    libpq-dev \
    # Required for SQL Server driver
    unixodbc-dev \
    gnupg2 \
    curl \
    # Required for Composer
    git \
    unzip \
    # Required for SQLite
    libsqlite3-dev \
    # Cleanup apt cache in same layer
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions for MySQL, PostgreSQL, SQLite
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    opcache

# Install SQL Server ODBC drivers and PHP extension
RUN set -eux; \
    # Determine architecture
    case $(dpkg --print-architecture) in \
        amd64) architecture="amd64" ;; \
        arm64) architecture="arm64" ;; \
        *) echo "Unsupported architecture"; exit 1 ;; \
    esac; \
    # Add Microsoft repository key
    curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl https://packages.microsoft.com/config/debian/12/prod.list | tee /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y msodbcsql18 mssql-tools18 \
    # Install build deps for pecl
    && apt-get install -y --no-install-recommends autoconf g++ make \
    # Install pdo_sqlsrv extension
    && pecl install pdo_sqlsrv \
    && docker-php-ext-enable pdo_sqlsrv \
    # Cleanup
    && apt-get purge -y autoconf g++ make \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# Install GLiNER PHP extension
RUN set -eux; \
    curl -fsSL -o /tmp/gliner.tar.gz https://github.com/ineersa/gliner-rs-php/releases/download/0.0.6/gliner-rs-php-0.0.6-linux-x86_64.tar.gz \
    && mkdir -p /tmp/gliner \
    && tar -xzf /tmp/gliner.tar.gz -C /tmp/gliner \
    && cp /tmp/gliner/libgliner_rs_php.so /usr/local/lib/php/extensions/libgliner_rs_php.so \
    && echo "extension=/usr/local/lib/php/extensions/libgliner_rs_php.so" > /usr/local/etc/php/conf.d/gliner_rs_php.ini \
    && rm -rf /tmp/gliner /tmp/gliner.tar.gz

# Configure OPcache for production
RUN { \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.enable_cli=1'; \
} > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Set PHP production settings
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for better layer caching
COPY composer.json composer.lock symfony.lock ./

# Set production environment BEFORE running composer scripts
# This ensures cache:clear knows not to load dev bundles like MakerBundle
ENV APP_ENV=prod

# Install dependencies without dev packages
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy application source (uses .dockerignore to exclude unwanted files)
COPY . ./

# Generate optimized autoloader
RUN composer dump-autoload --optimize --classmap-authoritative

# Note: For PII detection, mount GLiNER models via docker-compose volume:
# - ./models:/app/models:ro

# Create log directory
RUN mkdir -p /tmp/database-mcp/log

# The entrypoint is the MCP server
ENTRYPOINT ["php", "/app/bin/database-mcp"]
