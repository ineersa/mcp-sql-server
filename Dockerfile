# Database MCP Server - Production Dockerfile
# Supports: MySQL, PostgreSQL, SQLite, SQL Server

FROM php:8.4-cli-alpine

LABEL maintainer="Illia Vasylevskyi <ineersa@gmail.com>"
LABEL description="MCP server implementation to work with SQL databases"
LABEL version="0.0.2"

# Install system dependencies
RUN apk add --no-cache \
    # SSL certificates for HTTPS
    ca-certificates \
    # Required for pdo_pgsql
    libpq-dev \
    postgresql-dev \
    # Required for SQL Server driver
    unixodbc-dev \
    gnupg \
    curl \
    # Required for Composer
    git \
    unzip \
    # Required for SQLite
    sqlite-dev \
    # For healthchecks
    bash

# Install PHP extensions for MySQL, PostgreSQL, SQLite
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    opcache

# Install SQL Server ODBC drivers and PHP extension
# Microsoft provides Alpine packages for ODBC driver (version 18.6.1.1)
RUN set -eux; \
    # Determine architecture
    case $(uname -m) in \
        x86_64) architecture="amd64" ;; \
        aarch64) architecture="arm64" ;; \
        *) echo "Unsupported architecture"; exit 1 ;; \
    esac; \
    # Install required packages for building sqlsrv
    apk add --no-cache --virtual .build-deps \
        autoconf \
        g++ \
        make \
    # Microsoft ODBC Driver for SQL Server (Alpine)
    # Using -k since Microsoft's SSL chain may not be in Alpine's ca-certificates
    && curl -kO https://download.microsoft.com/download/9dcab408-e0d4-4571-a81a-5a0951e3445f/msodbcsql18_18.6.1.1-1_${architecture}.apk \
    && curl -kO https://download.microsoft.com/download/b60bb8b6-d398-4819-9950-2e30cf725fb0/mssql-tools18_18.6.1.1-1_${architecture}.apk \
    # Install the packages (allow untrusted for Microsoft packages)
    && apk add --allow-untrusted msodbcsql18_18.6.1.1-1_${architecture}.apk \
    && apk add --allow-untrusted mssql-tools18_18.6.1.1-1_${architecture}.apk \
    # Install pdo_sqlsrv extension
    && pecl install pdo_sqlsrv \
    && docker-php-ext-enable pdo_sqlsrv \
    # Cleanup
    && rm -f msodbcsql18_18.6.1.1-1_${architecture}.apk mssql-tools18_18.6.1.1-1_${architecture}.apk \
    && apk del .build-deps \
    && rm -rf /tmp/pear

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

# Create log directory
RUN mkdir -p /tmp/database-mcp/log

# The entrypoint is the MCP server
ENTRYPOINT ["php", "/app/bin/database-mcp"]
