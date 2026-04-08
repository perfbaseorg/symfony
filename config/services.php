<?php

declare(strict_types=1);

use Perfbase\Symfony\EventSubscriber\ConsoleProfilerSubscriber;
use Perfbase\Symfony\EventSubscriber\HttpProfilerSubscriber;
use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\Symfony\Support\PerfbaseErrorHandler;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->set(PerfbaseErrorHandler::class)
        ->arg('$debug', param('perfbase.debug'))
        ->arg('$logErrors', param('perfbase.log_errors'));

    $services->set(PerfbaseClientProvider::class)
        ->arg('$config', param('perfbase.config'));

    $services->set(HttpProfilerSubscriber::class)
        ->arg('$config', param('perfbase.config'))
        ->arg('$environment', param('kernel.environment'))
        ->arg('$appVersion', param('perfbase.app_version'))
        ->arg('$router', service('router')->nullOnInvalid())
        ->arg('$tokenStorage', service('security.token_storage')->nullOnInvalid());

    $services->set(ConsoleProfilerSubscriber::class)
        ->arg('$config', param('perfbase.config'))
        ->arg('$environment', param('kernel.environment'))
        ->arg('$appVersion', param('perfbase.app_version'));
};
