<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Fixtures;

use Perfbase\Symfony\PerfbaseBundle;
use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @var array<string, mixed> */
    private array $perfbaseConfig;

    /**
     * @param array<string, mixed> $perfbaseConfig
     */
    public function __construct(array $perfbaseConfig = [])
    {
        parent::__construct('test', true);
        $this->perfbaseConfig = $perfbaseConfig;
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new PerfbaseBundle(),
        ];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/perfbase-symfony-cache-' . $this->cacheKey();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/perfbase-symfony-log-' . $this->cacheKey();
    }

    /**
     * Kernels built with different perfbase config must never share a compiled
     * container. spl_object_id() is reused after garbage collection, so it let
     * a later kernel boot an earlier kernel's cached container and router.
     * __DIR__ keeps separate checkouts from sharing the temp-dir cache.
     */
    private function cacheKey(): string
    {
        return md5(__DIR__ . static::class . serialize($this->perfbaseConfig));
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'perfbase-test-secret',
            'test' => true,
            'router' => [
                'utf8' => true,
            ],
        ]);

        $container->extension('perfbase', array_replace_recursive([
            'enabled' => true,
            'api_key' => 'test-api-key',
            'sample_rate' => 1.0,
            'app_version' => 'test-suite',
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

        $services->set(PerfbaseClientProvider::class, TestPerfbaseClientProvider::class)->public();
        $services->set(TestController::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('app_article', '/articles/{id}')
            ->controller(TestController::class);

        $routes->add('app_dashboard', '/dashboard')
            ->controller(TestController::class);
    }
}
