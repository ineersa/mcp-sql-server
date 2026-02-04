<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DoctrineConfigLoader;
use App\Service\PIIAnalyzerService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for PIIAnalyzerService.
 *
 * These tests run the actual GLiNER PHP extension.
 */
class PIIAnalyzerServiceTest extends TestCase
{
    private DoctrineConfigLoader $configLoader;
    private static ?PIIAnalyzerService $sharedAnalyzer = null;

    public static function tearDownAfterClass(): void
    {
        self::$sharedAnalyzer = null;
    }

    protected function setUp(): void
    {
        $this->configLoader = new DoctrineConfigLoader(new NullLogger());
        $this->configLoader->loadAndValidate();

        if (null === self::$sharedAnalyzer) {
            self::$sharedAnalyzer = new PIIAnalyzerService(new NullLogger(), $this->configLoader);
        }
    }

    #[Test]
    public function itRedactsPiiFromRows(): void
    {
        // Simple case - only email is in our labels config
        $rows = [
            ['id' => 1, 'text' => 'My email is john.doe@example.com'],
            ['id' => 2, 'text' => 'Safe text without PII'],
        ];

        // Pass 0.4 threshold explicitly for tests
        $redacted = self::$sharedAnalyzer->redact($rows, 0.4);

        $this->assertCount(2, $redacted);
        $this->assertSame('1', $redacted[0]['id']);

        // Email should be redacted
        $this->assertStringContainsString('[REDACTED_', $redacted[0]['text']);
        $this->assertStringNotContainsString('john.doe@example.com', $redacted[0]['text']);

        // Safe text unchanged
        $this->assertSame('Safe text without PII', $redacted[1]['text']);
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
        $mediumText = 'I live in London and love it here.'; // city label

        $rows = [
            ['c' => $shortText],             // Short
            ['c' => $longText],              // Long
            ['c' => $mediumText],            // Medium with city PII
            ['c' => $longText.' Contact: bob@example.com'], // Long with email PII
        ];

        $redacted = self::$sharedAnalyzer->redact($rows, 0.4);

        $this->assertCount(4, $redacted);
        $this->assertSame($shortText, $redacted[0]['c']); // No PII
        $this->assertSame($longText, $redacted[1]['c']); // No PII

        // Check medium - city should be redacted
        $this->assertStringContainsString('[REDACTED_', $redacted[2]['c']);
        $this->assertStringNotContainsString('London', $redacted[2]['c']);

        // Check long with email PII
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

        $redacted = self::$sharedAnalyzer->redact($rows);

        $this->assertCount(3, $redacted);
        $this->assertNull($redacted[0]['val']);
        $this->assertSame('', $redacted[1]['val']);
        $this->assertSame('   ', $redacted[2]['val']);
    }
}
