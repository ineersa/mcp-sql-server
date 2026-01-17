<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

final class DoctrineConfigLoader
{
    /** @var array<string, array{name: string, type: string, version: ?string, connection: Connection}> */
    private array $connections = [];

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function loadAndValidate(): void
    {
        $configFile = $this->getConfigFilePath();

        if (!file_exists($configFile) || !is_readable($configFile)) {
            throw new \RuntimeException(\sprintf('Doctrine config file "%s" does not exist or is not readable.', $configFile));
        }

        $config = $this->parseConfigFile($configFile);

        $this->validateConfigStructure($config);

        $this->loadConnections($config);
    }

    /** @return array<string> */
    public function getConnectionNames(): array
    {
        return array_keys($this->connections);
    }

    public function getConnection(string $name): Connection
    {
        if (!isset($this->connections[$name])) {
            throw new \InvalidArgumentException(\sprintf('Connection "%s" is not configured.', $name));
        }

        return $this->connections[$name]['connection'];
    }

    /** @return array<string, Connection> */
    public function getAllConnections(): array
    {
        $result = [];
        foreach ($this->connections as $name => $data) {
            $result[$name] = $data['connection'];
        }

        return $result;
    }

    public function getConnectionType(string $name): ?string
    {
        return $this->connections[$name]['type'] ?? null;
    }

    public function getConnectionVersion(string $name): ?string
    {
        return $this->connections[$name]['version'] ?? null;
    }

    private function getConfigFilePath(): string
    {
        $configFile = $_ENV['DATABASE_CONFIG_FILE'] ?? null;

        if (null === $configFile || '' === $configFile) {
            throw new \RuntimeException('DATABASE_CONFIG_FILE environment variable is not set.');
        }

        // If relative path, resolve from project root
        if (!str_starts_with($configFile, '/')) {
            $projectRoot = \dirname(__DIR__, 2);
            $configFile = $projectRoot.'/'.$configFile;
        }

        return $configFile;
    }

    /** @return mixed[] */
    private function parseConfigFile(string $configFile): array
    {
        try {
            $content = file_get_contents($configFile);
            if (false === $content) {
                throw new \RuntimeException(\sprintf('Failed to read config file "%s".', $configFile));
            }

            $parsed = Yaml::parse($content);
            if (!\is_array($parsed)) {
                throw new \RuntimeException('Config file must contain a valid YAML configuration.');
            }

            return $this->resolveEnvVars($parsed);
        } catch (\Throwable $e) {
            throw new \RuntimeException(\sprintf('Failed to parse YAML config: %s', $e->getMessage()), previous: $e);
        }
    }

    /** @param mixed[] $config */
    private function validateConfigStructure(array $config): void
    {
        if (!isset($config['doctrine']['dbal'])) {
            throw new \RuntimeException('Config must contain "doctrine.dbal" section.');
        }

        $dbalConfig = $config['doctrine']['dbal'];
        if (!\is_array($dbalConfig)) {
            throw new \RuntimeException('"doctrine.dbal" must be an array.');
        }
    }

    /** @param mixed[] $config */
    private function loadConnections(array $config): void
    {
        $dbalConfig = $config['doctrine']['dbal'];

        if (isset($dbalConfig['connections'])) {
            if (!\is_array($dbalConfig['connections'])) {
                throw new \RuntimeException('"doctrine.dbal.connections" must be an array.');
            }

            foreach ($dbalConfig['connections'] as $name => $connectionConfig) {
                if (!\is_string($name)) {
                    throw new \RuntimeException('Connection names must be strings.');
                }

                $this->loadConnection($name, $connectionConfig);
            }
        } elseif (isset($dbalConfig['url'])) {
            $this->loadConnection('default', $dbalConfig);
        } else {
            throw new \RuntimeException('Config must contain either "doctrine.dbal.url" or "doctrine.dbal.connections".');
        }

        if ([] === $this->connections) {
            throw new \RuntimeException('No database connections defined in configuration.');
        }
    }

    /** @param mixed[] $connectionConfig */
    private function loadConnection(string $name, array $connectionConfig): void
    {
        $params = $this->extractConnectionParams($connectionConfig);
        $type = $this->extractDatabaseType($params);
        $version = $params['serverVersion'] ?? null;

        try {
            $connection = DriverManager::getConnection($params);

            $this->connections[$name] = [
                'name' => $name,
                'type' => $type,
                'version' => \is_string($version) ? $version : null,
                'connection' => $connection,
            ];

            $this->logger->info(\sprintf(
                'Successfully connected to database "%s" (type: %s, version: %s)',
                $name,
                $type,
                $version ?? 'unknown'
            ));
        } catch (Exception $e) {
            throw new \RuntimeException(\sprintf('Failed to create connection "%s": %s', $name, $e->getMessage()), previous: $e);
        }
    }

    /** @param mixed[] $config
     * @return array<string, mixed>
     */
    private function extractConnectionParams(array $config): array
    {
        $params = [];

        if (isset($config['url']) && \is_string($config['url'])) {
            $params['url'] = $config['url'];

            // Parse URL to extract components and driver
            $parsed = parse_url($config['url']);

            if (false !== $parsed) {
                // Detect driver from scheme if not provided
                if (!isset($config['driver']) && isset($parsed['scheme'])) {
                    $scheme = $parsed['scheme'];
                    if (str_starts_with($scheme, 'mysql') || str_starts_with($scheme, 'pdo-mysql')) {
                        $params['driver'] = 'pdo_mysql';
                    } elseif (str_starts_with($scheme, 'postgres') || str_starts_with($scheme, 'pdo-pgsql')) {
                        $params['driver'] = 'pdo_pgsql';
                    } elseif (str_starts_with($scheme, 'sqlite') || str_starts_with($scheme, 'pdo-sqlite')) {
                        $params['driver'] = 'pdo_sqlite';
                    } elseif (str_starts_with($scheme, 'sqlsrv') || str_starts_with($scheme, 'mssql') || str_starts_with($scheme, 'pdo-sqlsrv')) {
                        $params['driver'] = 'pdo_sqlsrv';
                    }
                }

                // populate params from URL if not explicitly set in config
                // This ensures that if DriverManager doesn't fully parse the URL when driver is set, we still have the values.
                if (isset($parsed['user']) && !isset($config['user'])) {
                    $params['user'] = $parsed['user'];
                }
                if (isset($parsed['pass']) && !isset($config['password'])) {
                    $params['password'] = $parsed['pass'];
                }
                if (isset($parsed['host']) && !isset($config['host'])) {
                    $params['host'] = $parsed['host'];
                }
                if (isset($parsed['port']) && !isset($config['port'])) {
                    $params['port'] = $parsed['port'];
                }
                if (isset($parsed['path']) && !isset($config['dbname'])) {
                    // path is usually /dbname
                    $path = ltrim($parsed['path'], '/');
                    if ('' !== $path) {
                        $params['dbname'] = $path;
                    }
                }
            }
        }

        // Always allow explicit overrides or fallback to standard components
        $keys = ['host', 'port', 'dbname', 'user', 'password', 'driver', 'memory', 'path', 'applicationIntent', 'encrypt', 'trustServerCertificate', 'driverOptions'];
        foreach ($keys as $key) {
            if (isset($config[$key])) {
                $params[$key] = $config[$key];
            }
        }

        // Handle version/serverVersion alias
        if (isset($config['version'])) {
            $params['serverVersion'] = $config['version'];
        } elseif (isset($config['serverVersion'])) {
            $params['serverVersion'] = $config['serverVersion'];
        }

        return $params;
    }

    /** @param mixed[] $params */
    private function extractDatabaseType(array $params): string
    {
        if (isset($params['url']) && \is_string($params['url'])) {
            if (str_starts_with($params['url'], 'mysql://')) {
                return 'mysql';
            }
            if (str_starts_with($params['url'], 'postgresql://') || str_starts_with($params['url'], 'postgres://')) {
                return 'postgresql';
            }
            if (str_starts_with($params['url'], 'sqlite://')) {
                return 'sqlite';
            }
            if (str_starts_with($params['url'], 'sqlsrv://')) {
                return 'sqlsrv';
            }

            return 'unknown';
        }

        $driver = $params['driver'] ?? null;
        if (\is_string($driver)) {
            return $driver;
        }

        return 'unknown';
    }

    /** @param mixed[] $data
     * @return array<string, mixed>
     */
    private function resolveEnvVars(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($value)) {
                $data[$key] = $this->resolveEnvVarsInString($value);
            } elseif (\is_array($value)) {
                $data[$key] = $this->resolveEnvVars($value);
            }
        }

        return $data;
    }

    private function resolveEnvVarsInString(string $value): string
    {
        return preg_replace_callback(
            '/%env\(([^)]+)\)%/',
            static function (array $matches): string {
                $varName = $matches[1];
                $value = $_ENV[$varName] ?? $_SERVER[$varName] ?? null;

                if (null === $value) {
                    throw new \RuntimeException(\sprintf('Environment variable "%s" is not defined.', $varName));
                }

                return $value;
            },
            $value
        ) ?: $value;
    }
}
