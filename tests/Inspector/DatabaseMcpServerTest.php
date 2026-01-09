<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

#[\PHPUnit\Framework\Attributes\CoversNothing]
class DatabaseMcpServerTest extends InspectorSnapshotTestCase
{
    private string $snapshotDir;

    protected function setUp(): void
    {
        $this->snapshotDir = \dirname(__DIR__, 2).'/tests/__snapshots__';
    }

    protected function getSnapshotFilePath(string $method, ?string $testName = null): string
    {
        $name = $testName ?? preg_replace('#[^a-zA-Z0-9]+#', '_', $method);

        return $this->snapshotDir.'/'.$name.'.json';
    }

    protected function getServerConnectionArgs(): array
    {
        return [
            \PHP_BINARY,
            \dirname(__DIR__, 2).'/bin/database-mcp',
        ];
    }

    protected function getTransport(): string
    {
        return 'stdio';
    }
}
