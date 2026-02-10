<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ModelDownloaderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'download-models',
    description: 'Downloads GLiNER PII detection models',
)]
final class DownloadModelsCommand extends Command
{
    private const string DEFAULT_TOKENIZER_PATH = 'models/tokenizer.json';
    private const string DEFAULT_MODEL_PATH = 'models/model.onnx';

    public function __construct(
        private ModelDownloaderService $modelDownloader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tokenizer-path', null, InputOption::VALUE_OPTIONAL, 'Path to save tokenizer.json', self::DEFAULT_TOKENIZER_PATH)
            ->addOption('model-path', null, InputOption::VALUE_OPTIONAL, 'Path to save model.onnx', self::DEFAULT_MODEL_PATH)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force download even if files exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tokenizerPath = $input->getOption('tokenizer-path');
        $modelPath = $input->getOption('model-path');
        $force = $input->getOption('force');

        if (!\is_string($tokenizerPath) || !\is_string($modelPath)) {
            $io->error('Paths must be strings.');

            return Command::FAILURE;
        }

        // Resolving relative paths
        $tokenizerPath = $this->resolvePath($tokenizerPath);
        $modelPath = $this->resolvePath($modelPath);

        $io->title('Downloading PII Detection Models');
        $io->text([
            'This command will download the GLiNER PII models from Hugging Face.',
            \sprintf('Tokenizer path: <info>%s</info>', $tokenizerPath),
            \sprintf('Model path: <info>%s</info>', $modelPath),
        ]);

        if (!$force && $this->modelDownloader->modelsExist($tokenizerPath, $modelPath)) {
            $io->success('Models already exist. Use --force to re-download.');

            return Command::SUCCESS;
        }

        try {
            if ($force) {
                if (file_exists($tokenizerPath)) {
                    @unlink($tokenizerPath);
                }
                if (file_exists($modelPath)) {
                    @unlink($modelPath);
                }
            }

            $this->modelDownloader->ensureModelsExist($tokenizerPath, $modelPath);
            $io->success('Models downloaded successfully!');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(\sprintf('Failed to download models: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return \dirname(__DIR__, 2).'/'.$path;
    }
}
