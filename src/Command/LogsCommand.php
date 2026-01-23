<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:logs',
    description: 'Pretty-print recent JSON logs from the latest log file. Shows a table by default; use --id to inspect a single entry.'
)]
class LogsCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ParameterBagInterface $params,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Log line number to pretty print')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many latest log rows to show', '100')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) ($input->getOption('limit') ?? 100);
        if ($limit <= 0) {
            $limit = 100;
        }

        $logFile = $this->resolveLatestLogFile();
        if (null === $logFile) {
            $io->warning('No log files found in '.($this->params->get('kernel.logs_dir') ?? 'var/log'));

            return Command::SUCCESS;
        }

        if (!is_readable($logFile)) {
            $io->error(\sprintf('Log file "%s" is not readable.', $logFile));

            return Command::FAILURE;
        }

        // We use FILE_IGNORE_NEW_LINES but keep empty lines to maintain line number mapping
        $lines = @file($logFile, \FILE_IGNORE_NEW_LINES) ?: [];
        if ([] === $lines) {
            $io->note(\sprintf('Log file "%s" is empty.', $logFile));

            return Command::SUCCESS;
        }

        $idOption = $input->getOption('id');
        if (null !== $idOption) {
            $id = (int) $idOption;
            if ($id < 1 || $id > \count($lines)) {
                $io->error(\sprintf('Invalid id %d. Valid range is 1..%d.', $id, \count($lines)));

                return Command::INVALID;
            }

            $line = $lines[$id - 1];
            $entry = json_decode($line, true);
            if (!\is_array($entry)) {
                $io->error(\sprintf('Line %d is not a valid JSON log entry.', $id));
                $io->text($line);

                return Command::FAILURE;
            }

            $this->renderSingleEntry($io, $entry, $id, $logFile);

            return Command::SUCCESS;
        }

        $rows = [];
        $count = 0;
        // Iterate backwards from the end of the file
        for ($i = \count($lines) - 1; $i >= 0 && $count < $limit; --$i) {
            $line = $lines[$i];
            // Skip empty lines or decode errors, but ID logic uses original line number ($i + 1)
            if ('' === trim($line)) {
                continue;
            }

            $entry = json_decode($line, true);
            if (\is_array($entry)) {
                $id = $i + 1;
                $levelName = (string) ($entry['level_name'] ?? ($entry['level'] ?? ''));
                $message = $this->stringify($entry['message'] ?? '');
                if ($contextPreview = $this->getContextPreview($entry['context'] ?? [])) {
                    $message .= "\n<fg=gray>".$contextPreview.'</>';
                }

                $rows[] = [
                    (string) $id,
                    $message,
                    (string) ($entry['channel'] ?? ''),
                    $this->getStyledLevelName($levelName),
                    (string) ($entry['datetime'] ?? ''),
                ];
                ++$count;
            }
        }

        $io->title('Latest logs from '.$logFile);
        $io->table(['id', 'message', 'channel', 'level_name', 'datetime'], $rows);
        $io->writeln(\sprintf('Showing %d of %d lines; most recent first. Use --id=<line> to view details.', $count, \count($lines)));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function renderSingleEntry(SymfonyStyle $io, array $entry, int $id, string $logFile): void
    {
        $io->title(\sprintf('Log entry #%d from %s', $id, $logFile));

        $io->definitionList(
            ['message' => $this->stringify($entry['message'] ?? '')],
            ['channel' => (string) ($entry['channel'] ?? '')],
            ['level_name' => (string) ($entry['level_name'] ?? ($entry['level'] ?? ''))],
            ['datetime' => (string) ($entry['datetime'] ?? '')],
        );

        if (!empty($entry['context'])) {
            $io->section('context');
            $io->writeln($this->prettyJson($entry['context']));
        }

        if (!empty($entry['extra'])) {
            $io->section('extra');
            $io->writeln($this->prettyJson($entry['extra']));
        }
    }

    private function resolveLatestLogFile(): ?string
    {
        $logsDir = (string) $this->params->get('kernel.logs_dir');
        $env = $this->kernel->getEnvironment();

        // Candidates: env.log (current) and rotated env-YYYY-MM-DD.log files
        $candidates = [];
        $patternRotated = \sprintf('%s/%s-*.log', rtrim($logsDir, '/'), $env);
        foreach (glob($patternRotated) ?: [] as $file) {
            $candidates[$file] = filemtime($file) ?: 0;
        }
        $currentPath = \sprintf('%s/%s.log', rtrim($logsDir, '/'), $env);
        if (file_exists($currentPath)) {
            $candidates[$currentPath] = filemtime($currentPath) ?: 0;
        }

        if ([] === $candidates) {
            return null;
        }

        arsort($candidates);

        return array_key_first($candidates);
    }

    private function stringify(mixed $value): string
    {
        if (\is_scalar($value) || null === $value) {
            return (string) $value;
        }

        return trim($this->prettyJson($value));
    }

    private function prettyJson(mixed $value): string
    {
        return json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function getStyledLevelName(string $levelName): string
    {
        $levelName = strtoupper($levelName);

        return match ($levelName) {
            'INFO' => \sprintf('<info>%s</info>', $levelName),
            'WARNING' => \sprintf('<comment>%s</comment>', $levelName),
            'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => \sprintf('<error>%s</error>', $levelName),
            'DEBUG' => \sprintf('<fg=gray>%s</>', $levelName),
            'NOTICE' => \sprintf('<fg=blue>%s</>', $levelName),
            default => $levelName,
        };
    }

    /**
     * @param array<mixed> $context
     */
    private function getContextPreview(array $context): string
    {
        if ([] === $context) {
            return '';
        }

        $lines = [];
        $count = 0;
        foreach ($context as $key => $value) {
            if ($count >= 3) {
                $lines[] = '...';
                break;
            }
            $valStr = \is_scalar($value) ? (string) $value : json_encode($value);
            if (\strlen($valStr) > 50) {
                $valStr = substr($valStr, 0, 47).'...';
            }
            $lines[] = \sprintf('%s: %s', $key, $valStr);
            ++$count;
        }

        return implode("\n", $lines);
    }
}
