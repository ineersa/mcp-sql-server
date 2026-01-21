<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DoctrineConfigLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(DoctrineConfigLoader::class)]
final class DoctrineConfigLoaderTest extends TestCase
{
    private string $tempDir;
    private string $configFile;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/doctrine_config_test_'.uniqid();
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->tempDir);
        $this->configFile = $this->tempDir.'/doctrine.yaml';

        $this->logger = $this->createStub(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        if (file_exists($this->tempDir)) {
            $filesystem->remove($this->tempDir);
        }

        unset($_ENV['DATABASE_CONFIG_FILE']);
    }

    public function testLoadAndValidateThrowsExceptionWhenEnvVarNotSet(): void
    {
        unset($_ENV['DATABASE_CONFIG_FILE']);

        $loader = new DoctrineConfigLoader($this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DATABASE_CONFIG_FILE environment variable is not set.');

        $loader->loadAndValidate();
    }

    public function testLoadAndValidateThrowsExceptionWhenFileDoesNotExist(): void
    {
        $_ENV['DATABASE_CONFIG_FILE'] = '/nonexistent/file.yaml';

        $loader = new DoctrineConfigLoader($this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Doctrine config file');

        $loader->loadAndValidate();
    }

    public function testLoadAndValidateThrowsExceptionForInvalidYaml(): void
    {
        file_put_contents($this->configFile, 'invalid yaml: [unclosed');
        $_ENV['DATABASE_CONFIG_FILE'] = $this->configFile;

        $loader = new DoctrineConfigLoader($this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse YAML config');

        $loader->loadAndValidate();
    }

    public function testLoadAndValidateThrowsExceptionWhenDoctrineSectionMissing(): void
    {
        file_put_contents($this->configFile, 'other: config');
        $_ENV['DATABASE_CONFIG_FILE'] = $this->configFile;

        $loader = new DoctrineConfigLoader($this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must contain "doctrine.dbal" section');

        $loader->loadAndValidate();
    }

    public function testLoadAndValidateThrowsExceptionWhenNoConnectionsDefined(): void
    {
        file_put_contents($this->configFile, <<<YAML
doctrine:
    dbal:
        connections: {}
YAML);
        $_ENV['DATABASE_CONFIG_FILE'] = $this->configFile;

        $loader = new DoctrineConfigLoader($this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No database connections defined');

        $loader->loadAndValidate();
    }

    public function testLoadAndValidateThrowsExceptionWhenConnectionFails(): void
    {
        file_put_contents($this->configFile, <<<YAML
doctrine:
    dbal:
        connections:
            default:
                driver: 'invalid_driver'
YAML);
        $_ENV['DATABASE_CONFIG_FILE'] = $this->configFile;

        $loader = new DoctrineConfigLoader($this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create connection');

        $loader->loadAndValidate();
    }

    public function testLoadAndValidateWithMissingEnvVarThrowsException(): void
    {
        file_put_contents($this->configFile, <<<YAML
doctrine:
    dbal:
        url: 'mysql://%env(MISSING_VAR)%@127.0.0.1:3306/test?serverVersion=8.0'
YAML);
        $_ENV['DATABASE_CONFIG_FILE'] = $this->configFile;

        $loader = new DoctrineConfigLoader($this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Environment variable "MISSING_VAR" is not defined');

        $loader->loadAndValidate();
    }

    public function testGetConnectionThrowsExceptionForNonExistentConnection(): void
    {
        $loader = new DoctrineConfigLoader($this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Connection "nonexistent" is not configured');

        $loader->getConnection('nonexistent');
    }

    public function testGetConnectionNamesReturnsEmptyArrayWhenNotLoaded(): void
    {
        $loader = new DoctrineConfigLoader($this->logger);

        $names = $loader->getConnectionNames();

        $this->assertSame([], $names);
    }

    public function testGetAllConnectionsReturnsEmptyArrayWhenNotLoaded(): void
    {
        $loader = new DoctrineConfigLoader($this->logger);

        $connections = $loader->getAllConnections();

        $this->assertSame([], $connections);
    }

    public function testGetConnectionTypeReturnsNullForNonExistentConnection(): void
    {
        $loader = new DoctrineConfigLoader($this->logger);

        $type = $loader->getConnectionType('nonexistent');

        $this->assertNull($type);
    }

    public function testGetConnectionVersionReturnsNullForNonExistentConnection(): void
    {
        $loader = new DoctrineConfigLoader($this->logger);

        $version = $loader->getConnectionVersion('nonexistent');

        $this->assertNull($version);
    }

    public function testLoadAndValidateWithMultipleConnectionsThrowsExceptionForInvalidConnectionsArray(): void
    {
        file_put_contents($this->configFile, <<<YAML
doctrine:
    dbal:
        connections: "not an array"
YAML);
        $_ENV['DATABASE_CONFIG_FILE'] = $this->configFile;

        $loader = new DoctrineConfigLoader($this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"doctrine.dbal.connections" must be an array');

        $loader->loadAndValidate();
    }

    public function testYamlParsingWithEnvVarSubstitution(): void
    {
        $_ENV['DATABASE_CONFIG_FILE'] = $this->configFile;
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_NAME'] = 'testdb';

        file_put_contents($this->configFile, <<<YAML
test_var: "%env(DB_HOST)%"
test_var2: "%env(DB_NAME)%"
YAML);

        $loader = new DoctrineConfigLoader($this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must contain "doctrine.dbal" section');

        $loader->loadAndValidate();
    }
}
