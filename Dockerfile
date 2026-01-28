# Database MCP Server - Production Dockerfile
# Supports: MySQL, PostgreSQL, SQLite, SQL Server
# Includes GLiNER for PII detection

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
    # Python 3.11 for GLiNER (prebuilt wheels available)
    python3.11 \
    python3.11-venv \
    python3-pip \
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

# Make python3.11 the default python3
RUN update-alternatives --install /usr/bin/python3 python3 /usr/bin/python3.11 1

# Install Python dependencies for GLiNER (CPU-only PyTorch for smaller image)
# Using prebuilt wheels from PyTorch index for fast installation
RUN pip3 install --break-system-packages --no-cache-dir \
    torch --index-url https://download.pytorch.org/whl/cpu \
    && pip3 install --break-system-packages --no-cache-dir gliner>=0.2.0

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

# Copy GLiNER script
COPY scripts/gliner_pii.py /app/scripts/gliner_pii.py

# Create log directory
RUN mkdir -p /tmp/database-mcp/log

# The entrypoint is the MCP server
ENTRYPOINT ["php", "/app/bin/database-mcp"]
