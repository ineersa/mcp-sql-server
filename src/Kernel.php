<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__.'/../';
    }

    public function getLogDir(): string
    {
        $dir = $_SERVER['APP_LOG_DIR']
            ?? $_ENV['APP_LOG_DIR']
            ?? getenv('APP_LOG_DIR');

        if ($dir) {
            return rtrim($dir, '/');
        }

        return parent::getLogDir();
    }
}
