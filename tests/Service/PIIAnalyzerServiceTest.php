<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PIIAnalyzerService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for PIIAnalyzerService.
 *
 * These tests run the actual Python GLiNER script.
 */
class PIIAnalyzerServiceTest extends TestCase
{
    private static ?PIIAnalyzerService $analyzer = null;

    public static function setUpBeforeClass(): void
    {
        self::$analyzer = new PIIAnalyzerService(new NullLogger());
        self::$analyzer->start(waitForReady: true);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$analyzer) {
            self::$analyzer->stop();
            self::$analyzer = null;
        }
    }

    #[Test]
    public function itRedactsPiiFromRows(): void
    {
        // Simple case
        $rows = [
            ['id' => 1, 'text' => 'My email is john.doe@example.com'],
            ['id' => 2, 'text' => 'Call me at 555-0199'],
        ];

        $redacted = self::$analyzer->redact($rows);

        $this->assertCount(2, $redacted);
        $this->assertSame(1, $redacted[0]['id']);

        // Let's check for REDACTED_ mark
        $this->assertStringContainsString('[REDACTED_', $redacted[0]['text']);
        $this->assertStringNotContainsString('john.doe@example.com', $redacted[0]['text']);

        $this->assertStringContainsString('[REDACTED_', $redacted[1]['text']);
        $this->assertStringNotContainsString('555-0199', $redacted[1]['text']);
    }

    #[Test]
    public function itHandlesLongTextsAndSorting(): void
    {
        // This tests the sorting optimization logic.
        // We create rows with varying length texts.

        // Note: GLiNER has a context limit (usually 512 tokens).
        // 50 iterations * ~25 chars = ~1250 chars (~300-400 tokens), should fit.
        $longText = str_repeat('This is a safe sentence. ', 50);
        $shortText = 'Hello world';
        $mediumText = 'My name is Alice and I live in London.';

        $rows = [
            ['c' => $shortText],             // Short
            ['c' => $longText],              // Long
            ['c' => $mediumText],            // Medium
            ['c' => $longText.' Contact: bob@example.com'], // Long with PII at end
        ];

        $redacted = self::$analyzer->redact($rows);

        $this->assertCount(4, $redacted);
        $this->assertSame($shortText, $redacted[0]['c']); // No PII
        $this->assertSame($longText, $redacted[1]['c']); // No PII

        // Check medium
        $this->assertStringContainsString('[REDACTED_', $redacted[2]['c']);
        $this->assertStringNotContainsString('Alice', $redacted[2]['c']);

        // Check long with PII
        $this->assertStringContainsString('[REDACTED_', $redacted[3]['c']);
        $this->assertStringNotContainsString('bob@example.com', $redacted[3]['c']);
    }

    #[Test]
    public function itHandlesEmptyAndNull(): void
    {
        $rows = [
            ['val' => null],
            ['val' => ''],
            ['val' => '   '],
        ];

        $redacted = self::$analyzer->redact($rows);

        $this->assertCount(3, $redacted);
        $this->assertNull($redacted[0]['val']);
        $this->assertSame('', $redacted[1]['val']);
        $this->assertSame('   ', $redacted[2]['val']);
    }
}
