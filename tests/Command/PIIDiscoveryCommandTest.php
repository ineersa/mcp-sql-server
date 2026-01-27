<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PIIDiscoveryCommand;
use App\Service\DoctrineConfigLoader;
use App\Service\PIIAnalyzerService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for PII Discovery Command.
 *
 * These tests require the Docker environment with Python/GLiNER.
 * Run with: composer tests
 */
final class PIIDiscoveryCommandTest extends TestCase
{
    private DoctrineConfigLoader $configLoader;
    private static ?PIIAnalyzerService $sharedAnalyzer = null;
    private CommandTester $commandTester;
    private Application $application;

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$sharedAnalyzer) {
            self::$sharedAnalyzer->stop();
            self::$sharedAnalyzer = null;
        }
    }

    protected function setUp(): void
    {
        $this->configLoader = new DoctrineConfigLoader(new NullLogger());
        $this->configLoader->loadAndValidate();

        if (null === self::$sharedAnalyzer) {
            self::$sharedAnalyzer = new PIIAnalyzerService(new NullLogger());
        }

        $command = new PIIDiscoveryCommand(
            new NullLogger(),
            $this->configLoader,
            self::$sharedAnalyzer
        );

        $this->application = new Application();
        $this->application->addCommand($command);

        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function itRequiresConnectionOption(): void
    {
        $this->commandTester->execute([]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('--connection option is required', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itFailsForInvalidConnection(): void
    {
        $this->commandTester->execute([
            '--connection' => 'nonexistent',
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('not found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itDetectsPiiInPiiSamplesTable(): void
    {
        $this->commandTester->execute([
            '--connection' => 'local',
            '--tables' => 'pii_samples',
        ]);

        $display = $this->commandTester->getDisplay();

        // Check command succeeded
        $this->assertSame(0, $this->commandTester->getStatusCode(), 'Command failed: '.$display);

        // Check that PII was detected in expected columns
        $this->assertStringContainsString('pii_samples:', $display);

        // Verify that the email column was detected with email PII type
        $this->assertStringContainsString('customer_email:', $display);
        $this->assertStringContainsString('email', $display);

        // Verify that at least some columns were detected (exact labels may vary by model version)
        // The model should detect most of: customer_name, phone, ip_address, etc.
        $this->assertStringContainsString('customer_name:', $display);

        // Verify nice table output is also present
        $this->assertStringContainsString('Processing table pii_samples...', $display);
        $this->assertStringContainsString('Column', $display);
        $this->assertStringContainsString('PII Type(s)', $display);
        $this->assertStringContainsString('Sample', $display);
    }

    #[Test]
    public function itRespectsTableFilter(): void
    {
        $this->commandTester->execute([
            '--connection' => 'local',
            '--tables' => 'users',
        ]);

        $display = $this->commandTester->getDisplay();

        $this->assertSame(0, $this->commandTester->getStatusCode());

        // Should process users table
        if (str_contains($display, 'users:')) {
            $this->assertStringContainsString('email', $display);
        }

        // Should NOT process pii_samples since it was filtered out
        $this->assertStringNotContainsString('pii_samples:', $display);
    }

    #[Test]
    public function itHandlesTableWithFewRecords(): void
    {
        // Request more samples than exist (only 3 pii_samples records)
        $this->commandTester->execute([
            '--connection' => 'local',
            '--tables' => 'pii_samples',
            '--sample-size' => '100',
        ]);

        // Should still work with available records
        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    #[Test]
    public function itRespectsConfidenceThreshold(): void
    {
        // Very high threshold should reduce false positives
        $this->commandTester->execute([
            '--connection' => 'local',
            '--tables' => 'pii_samples',
            '--confidence-threshold' => '0.99',
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    #[Test]
    public function itShowsHelpWithPiiLabels(): void
    {
        // Test that the command help text includes PII labels
        $helpText = $this->application->find('pii:discover')->getHelp();

        // Should list PII categories
        $this->assertStringContainsString('Personal:', $helpText);
        $this->assertStringContainsString('Contact:', $helpText);
        $this->assertStringContainsString('Financial:', $helpText);
        $this->assertStringContainsString('first_name', $helpText);
        $this->assertStringContainsString('email', $helpText);
        $this->assertStringContainsString('ssn', $helpText);
    }
}
