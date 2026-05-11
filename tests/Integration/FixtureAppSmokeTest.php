<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Integration;

use Perfbase\Symfony\FixtureApp\FakePerfbaseClientProvider;
use Perfbase\Symfony\FixtureApp\FakeRecordingPerfbaseClient;
use Perfbase\Symfony\FixtureApp\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class FixtureAppSmokeTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        FakePerfbaseClientProvider::$client = null;

        if ($this->kernel !== null) {
            $this->kernel->shutdown();
            $this->kernel = null;
        }
    }

    public function test_fixture_app_http_flow_profiles_real_kernel_request(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        $client = new FakeRecordingPerfbaseClient();
        FakePerfbaseClientProvider::$client = $client;

        $kernel = $this->bootKernel();
        $request = Request::create('https://example.test/fixture/articles/123?token=secret', 'GET');

        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());

        $kernel->terminate($request, $response);

        self::assertSame(['http'], $client->startedSpans);
        self::assertSame('GET /fixture/articles/{id}', $client->attributes['action']);
        self::assertSame('https://example.test/fixture/articles/123', $client->attributes['http_url']);
        self::assertSame('200', $client->attributes['http_status_code']);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_fixture_app_console_flow_profiles_real_command(): void
    {
        $client = new FakeRecordingPerfbaseClient();
        FakePerfbaseClientProvider::$client = $client;

        $application = new Application($this->bootKernel());
        $application->setAutoExit(false);

        $exitCode = $application->run(
            new ArrayInput(['command' => 'perfbase:fixture']),
            new BufferedOutput()
        );

        self::assertSame(0, $exitCode);
        self::assertSame(['cli'], $client->startedSpans);
        self::assertSame('perfbase:fixture', $client->attributes['action']);
        self::assertSame('0', $client->attributes['exit_code']);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_fixture_app_disabled_config_keeps_request_flow_inert(): void
    {
        $client = new FakeRecordingPerfbaseClient();
        FakePerfbaseClientProvider::$client = $client;

        $kernel = new Kernel('test_disabled', true, [
            'enabled' => false,
        ]);
        $this->kernel = $kernel;
        $kernel->boot();

        $request = Request::create('https://example.test/fixture/health', 'GET');
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(200, $response->getStatusCode());

        $kernel->terminate($request, $response);

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_fixture_app_invalid_perfbase_config_fails_on_boot(): void
    {
        $kernel = new Kernel('test_invalid', true, [
            'enabled' => true,
            'api_key' => '',
        ]);

        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $kernel->boot();
    }

    private function bootKernel(): Kernel
    {
        if ($this->kernel === null) {
            $this->kernel = new Kernel('test', true);
            $this->kernel->boot();
        }

        return $this->kernel;
    }
}
