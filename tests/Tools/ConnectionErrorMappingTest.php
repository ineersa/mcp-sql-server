<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use App\Exception\ToolUsageError;
use App\Tools\QueryTool;
use App\Tools\SchemaTool;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionErrorMappingTest extends TestCase
{
    /** @return iterable<string, array{class-string, \Throwable, bool}> */
    public static function errors(): iterable
    {
        foreach ([QueryTool::class, SchemaTool::class] as $tool) {
            $driver = new class extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
                public function getSQLState(): string
                {
                    return '08006';
                }
            };
            yield $tool.' typed' => [$tool, new ConnectionException($driver, null), true];
            yield $tool.' state' => [$tool, new DriverException($driver, null), true];
            yield $tool.' wrapped' => [$tool, new \RuntimeException('Wrapper', 0, new \RuntimeException('SSL SYSCALL error: EOF detected')), true];
            yield $tool.' mysql' => [$tool, new \RuntimeException('server has gone away'), true];
            yield $tool.' sqlserver' => [$tool, new \RuntimeException('Communication link failure'), true];
            yield $tool.' other' => [$tool, new DriverException(new class extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
                public function getSQLState(): string
                {
                    return '42000';
                }
            }, null), false];
        }
    }

    /** @param class-string $toolClass */
    #[DataProvider('errors')]
    public function testMapsConnectionErrors(string $toolClass, \Throwable $error, bool $connectionFailure): void
    {
        $class = new \ReflectionClass($toolClass);
        $tool = $class->newInstanceWithoutConstructor();
        $mapped = $class->getMethod('mapThrowableToToolUsageError')->invoke($tool, $error);

        $this->assertInstanceOf(ToolUsageError::class, $mapped);
        $this->assertSame($error, $mapped->getPrevious());
        $this->assertSame($error->getMessage(), $mapped->getMessage());
        $this->assertSame(!$connectionFailure, $mapped->isRetryable());
        if ($connectionFailure) {
            $this->assertStringContainsString('Database connection failed.', $mapped->getHint() ?? '');
            $this->assertStringContainsString('reconnect the MCP server', $mapped->getHint() ?? '');
        }
    }
}
