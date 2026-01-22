<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SafeQueryExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;

final class SafeQueryExecutorTest extends TestCase
{
    private SafeQueryExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new SafeQueryExecutor();
    }

    /**
     * @dataProvider forbiddenKeywordsProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenKeywordsProvider')]
    public function testBlocksForbiddenKeywords(string $sql, string $expectedKeyword): void
    {
        $connection = $this->createMock(Connection::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(\sprintf('Security violation: Keyword "%s" is not allowed', $expectedKeyword));

        $this->executor->execute($connection, $sql);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbiddenKeywordsProvider(): array
    {
        return [
            'INSERT' => ['INSERT INTO users (name) VALUES ("test")', 'INSERT'],
            'UPDATE' => ['UPDATE users SET name = "test"', 'UPDATE'],
            'DELETE' => ['DELETE FROM users WHERE id = 1', 'DELETE'],
            'DROP' => ['DROP TABLE users', 'DROP'],
            'CREATE' => ['CREATE TABLE test (id INT)', 'CREATE'],
            'ALTER' => ['ALTER TABLE users ADD COLUMN test VARCHAR(255)', 'ALTER'],
            'TRUNCATE' => ['TRUNCATE TABLE users', 'TRUNCATE'],
            'EXEC' => ['EXEC sp_test', 'EXEC'],
            'EXECUTE' => ['EXECUTE sp_test', 'EXECUTE'],
            'INTO' => ['SELECT * INTO new_table FROM users', 'INTO'],
            'MERGE' => ['MERGE INTO users USING source ON users.id = source.id', 'MERGE'],
            'GRANT' => ['GRANT SELECT ON users TO test_user', 'GRANT'],
            'REVOKE' => ['REVOKE SELECT ON users FROM test_user', 'REVOKE'],
            'COMMIT' => ['COMMIT', 'COMMIT'],
            'ROLLBACK' => ['ROLLBACK', 'ROLLBACK'],
            'TRANSACTION' => ['BEGIN TRANSACTION', 'TRANSACTION'],
        ];
    }

    public function testBlocksForbiddenKeywordsInComments(): void
    {
        $connection = $this->createMock(Connection::class);

        // Comments should be stripped, so INSERT in comment should not trigger
        $sql = '-- INSERT comment
SELECT * FROM users';

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([['id' => 1]]);

        $connection->expects($this->once())
            ->method('beginTransaction');

        $connection->expects($this->once())
            ->method('executeQuery')
            ->with($sql)
            ->willReturn($result);

        $connection->expects($this->once())
            ->method('isTransactionActive')
            ->willReturn(true);

        $connection->expects($this->once())
            ->method('rollBack');

        $rows = $this->executor->execute($connection, $sql);

        $this->assertSame([['id' => 1]], $rows);
    }

    public function testAllowsSelectQueries(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);

        $sql = 'SELECT * FROM users WHERE id = 1';
        $expectedRows = [['id' => 1, 'name' => 'Test']];

        $result->method('fetchAllAssociative')->willReturn($expectedRows);

        $connection->expects($this->once())
            ->method('beginTransaction');

        $connection->expects($this->once())
            ->method('executeQuery')
            ->with($sql)
            ->willReturn($result);

        $connection->expects($this->once())
            ->method('isTransactionActive')
            ->willReturn(true);

        $connection->expects($this->once())
            ->method('rollBack');

        $rows = $this->executor->execute($connection, $sql);

        $this->assertSame($expectedRows, $rows);
    }

    public function testAlwaysRollsBackTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);

        $result->method('fetchAllAssociative')->willReturn([]);

        $connection->expects($this->once())
            ->method('beginTransaction');

        $connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $connection->expects($this->once())
            ->method('isTransactionActive')
            ->willReturn(true);

        $connection->expects($this->once())
            ->method('rollBack');

        $this->executor->execute($connection, 'SELECT 1');
    }

    public function testRollsBackEvenOnQueryException(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects($this->once())
            ->method('beginTransaction');

        $connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \Exception('Query error'));

        $connection->expects($this->once())
            ->method('isTransactionActive')
            ->willReturn(true);

        $connection->expects($this->once())
            ->method('rollBack');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Query error');

        $this->executor->execute($connection, 'SELECT 1');
    }
}
