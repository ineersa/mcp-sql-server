<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ComposerMetadataExtractor
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getVersion(): string
    {
        $composerJson = $this->getComposerJson();
        if (!isset($composerJson['version'])) {
            throw new \RuntimeException('Version not found in composer.json');
        }

        return $composerJson['version'];
    }

    public function getName(): string
    {
        $composerJson = $this->getComposerJson();
        if (!isset($composerJson['name'])) {
            throw new \RuntimeException('name not found in composer.json');
        }

        return $composerJson['name'];
    }

    public function getDescription(): string
    {
        $composerJson = $this->getComposerJson();
        if (!isset($composerJson['description'])) {
            throw new \RuntimeException('description not found in composer.json');
        }

        return $composerJson['description'];
    }

    /**
     * @return array<string, mixed>
     */
    private function getComposerJson(): array
    {
        $composerJsonPath = $this->projectDir.'/composer.json';
        if (!file_exists($composerJsonPath)) {
            throw new \RuntimeException('composer.json not found');
        }

        $content = file_get_contents($composerJsonPath);
        if (false === $content) {
            throw new \RuntimeException('Unable to read composer.json');
        }

        $data = json_decode($content, true);
        if (\JSON_ERROR_NONE !== json_last_error()) {
            throw new \RuntimeException('Invalid JSON in composer.json');
        }

        return $data;
    }
}
