<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'secret' => 'perfbase-fixture-secret',
        'test' => true,
        'router' => [
            'utf8' => true,
        ],
    ]);
};
