<?php

declare(strict_types=1);

namespace Perfbase\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class PerfbaseExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('perfbase.config', $config);
        $container->setParameter('perfbase.debug', $config['debug']);
        $container->setParameter('perfbase.log_errors', $config['log_errors']);
        $container->setParameter('perfbase.app_version', $config['app_version']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }
}
