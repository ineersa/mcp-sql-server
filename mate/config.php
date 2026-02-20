<?php

declare(strict_types=1);

// User's service configuration file
// This file is loaded into the Symfony DI container

use Symfony\AI\Mate\Container\MateHelper;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        // Override default parameters here
        // ->set('mate.cache_dir', sys_get_temp_dir().'/mate')
        // ->set('mate.env_file', ['.env']) // This will load mate/.env and mate/.env.local
        ->set('ai_mate_monolog.log_dir', 'mate.root_dir/var/log');

    $container->services()
        // Register your custom services here
    ;

    MateHelper::disableFeatures($container, [
        'symfony/ai-mate' => [
            'php-version',
            'operating-system',
            'operating-system-family',
            'php-extensions',
        ],
        'symfony/ai-symfony-mate-extension' => [
            'symfony-profiler-list',
            'symfony-profiler-latest',
            'symfony-profiler-search',
            'symfony-profiler-get',
        ],
        'symfony/ai-monolog-mate-extension' => [],
    ]);
};
