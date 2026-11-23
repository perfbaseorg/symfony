<?php

declare(strict_types=1);

namespace Perfbase\Symfony\FixtureApp;

use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** @var array<string, mixed> */
    private array $perfbaseConfig;

    /**
     * @param array<string, mixed> $perfbaseConfig
     */
    public function __construct(string $environment, bool $debug, array $perfbaseConfig = [])
    {
        parent::__construct($environment, $debug);
        $this->perfbaseConfig = $perfbaseConfig;
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * One compiled container per environment *and* perfbase config override,
     * so kernels booted with different overrides never reuse each other's cache.
     */
    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->environment . '-' . $this->configKey();
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    private function configKey(): string
    {
        return substr(md5(serialize($this->perfbaseConfig)), 0, 12);
    }

    public function registerBundles(): iterable
    {
        $bundles = require $this->getProjectDir() . '/config/bundles.php';

        foreach ($bundles as $class => $envs) {
            if (($envs[$this->environment] ?? $envs['all'] ?? false) === true) {
                yield new $class();
            }
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import($this->getProjectDir() . '/config/packages/framework.php');
        $container->extension('perfbase', array_replace_recursive([
            'enabled' => true,
            'api_key' => 'fixture-api-key',
            'sample_rate' => 1.0,
            'app_version' => 'fixture-app',
            'include' => [
                'http' => ['*'],
                'console' => ['*'],
            ],
            'exclude' => [
                'http' => [],
                'console' => [],
            ],
        ], $this->perfbaseConfig));

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

        $services->load('Perfbase\\Symfony\\FixtureApp\\', $this->getProjectDir() . '/src/*')
            ->exclude([
                $this->getProjectDir() . '/src/Kernel.php',
            ]);

        $services->set(FixtureController::class)->public();

        if (strpos($this->environment, 'test') === 0) {
            $services->set(PerfbaseClientProvider::class, FakePerfbaseClientProvider::class)->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('fixture_article', '/fixture/articles/{id}')
            ->controller([FixtureController::class, 'show']);

        $routes->add('fixture_health', '/fixture/health')
            ->controller([FixtureController::class, 'health']);
    }
}
