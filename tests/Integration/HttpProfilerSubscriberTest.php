<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Integration;

use Perfbase\Symfony\Tests\Fixtures\RecordingPerfbaseClient;
use Perfbase\Symfony\Tests\Fixtures\TestKernel;
use Perfbase\Symfony\Tests\Fixtures\TestPerfbaseClientProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class HttpProfilerSubscriberTest extends TestCase
{
    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        TestPerfbaseClientProvider::$client = null;

        if ($this->kernel !== null) {
            $this->kernel->shutdown();
            $this->kernel = null;
        }
    }

    public function test_request_response_terminate_flow_profiles_main_request(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        $client = new RecordingPerfbaseClient();
        TestPerfbaseClientProvider::$client = $client;

        $kernel = $this->bootKernel();
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $request = Request::create('https://example.com/articles/42?token=secret', 'GET');
        $request->attributes->set('_route', 'app_article');
        $request->attributes->set('_controller', 'Perfbase\\Symfony\\Tests\\Fixtures\\TestController');

        $dispatcher->dispatch(
            new RequestEvent($kernel, $request, $this->mainRequestType()),
            'kernel.request'
        );
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, $this->mainRequestType(), new Response('ok', 202)),
            'kernel.response'
        );
        $dispatcher->dispatch(
            new TerminateEvent($kernel, $request, new Response('ok', 202)),
            'kernel.terminate'
        );

        self::assertSame(['http'], $client->startedSpans);
        self::assertSame('GET /articles/{id}', $client->attributes['action']);
        self::assertSame('https://example.com/articles/42?token=REDACTED', $client->attributes['http_url']);
        self::assertSame('202', $client->attributes['http_status_code']);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_exception_flow_captures_exception_and_status_code(): void
    {
        $client = new RecordingPerfbaseClient();
        TestPerfbaseClientProvider::$client = $client;

        $kernel = $this->bootKernel();
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $request = Request::create('/articles/42', 'GET');
        $request->attributes->set('_route', 'app_article');
        $request->attributes->set('_controller', 'Perfbase\\Symfony\\Tests\\Fixtures\\TestController');

        $dispatcher->dispatch(
            new RequestEvent($kernel, $request, $this->mainRequestType()),
            'kernel.request'
        );
        $dispatcher->dispatch(
            new ExceptionEvent($kernel, $request, $this->mainRequestType(), new NotFoundHttpException('missing')),
            'kernel.exception'
        );
        $dispatcher->dispatch(
            new TerminateEvent($kernel, $request, new Response('missing', 404)),
            'kernel.terminate'
        );

        self::assertSame('missing', $client->attributes['exception']);
        self::assertSame('404', $client->attributes['http_status_code']);
        self::assertSame(0, $client->submitCalls);
        self::assertSame(1, $client->resetCalls);
    }

    public function test_subrequest_is_ignored(): void
    {
        $client = new RecordingPerfbaseClient();
        TestPerfbaseClientProvider::$client = $client;

        $kernel = $this->bootKernel();
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $request = Request::create('/dashboard', 'GET');
        $request->attributes->set('_route', 'app_dashboard');

        $dispatcher->dispatch(
            new RequestEvent($kernel, $request, $this->subRequestType()),
            'kernel.request'
        );

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_custom_allowed_status_code_is_submitted(): void
    {
        $client = new RecordingPerfbaseClient();
        TestPerfbaseClientProvider::$client = $client;

        $kernel = $this->bootKernel([
            'profile_http_status_codes' => [200, 404],
        ]);
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $request = Request::create('/missing', 'GET');
        $request->attributes->set('_route', 'app_article');
        $request->attributes->set('_controller', 'Perfbase\\Symfony\\Tests\\Fixtures\\TestController');

        $dispatcher->dispatch(
            new RequestEvent($kernel, $request, $this->mainRequestType()),
            'kernel.request'
        );
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, $this->mainRequestType(), new Response('missing', 404)),
            'kernel.response'
        );
        $dispatcher->dispatch(
            new TerminateEvent($kernel, $request, new Response('missing', 404)),
            'kernel.terminate'
        );

        self::assertSame('404', $client->attributes['http_status_code']);
        self::assertSame(1, $client->submitCalls);
        self::assertSame(0, $client->resetCalls);
    }

    public function test_server_error_status_code_is_submitted_by_default(): void
    {
        $client = new RecordingPerfbaseClient();
        TestPerfbaseClientProvider::$client = $client;

        $kernel = $this->bootKernel();
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $request = Request::create('/explode', 'GET');
        $request->attributes->set('_route', 'app_article');
        $request->attributes->set('_controller', 'Perfbase\\Symfony\\Tests\\Fixtures\\TestController');

        $dispatcher->dispatch(
            new RequestEvent($kernel, $request, $this->mainRequestType()),
            'kernel.request'
        );
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, $this->mainRequestType(), new Response('error', 500)),
            'kernel.response'
        );
        $dispatcher->dispatch(
            new TerminateEvent($kernel, $request, new Response('error', 500)),
            'kernel.terminate'
        );

        self::assertSame('500', $client->attributes['http_status_code']);
        self::assertSame(1, $client->submitCalls);
        self::assertSame(0, $client->resetCalls);
    }

    /**
     * @param array<string, mixed> $perfbaseConfig
     */
    private function bootKernel(array $perfbaseConfig = []): TestKernel
    {
        if ($this->kernel === null) {
            $this->kernel = new TestKernel($perfbaseConfig);
            $this->kernel->boot();
        }

        return $this->kernel;
    }

    private function mainRequestType(): int
    {
        return defined(HttpKernelInterface::class . '::MAIN_REQUEST')
            ? HttpKernelInterface::MAIN_REQUEST
            : 1;
    }

    private function subRequestType(): int
    {
        return defined(HttpKernelInterface::class . '::SUB_REQUEST')
            ? HttpKernelInterface::SUB_REQUEST
            : 2;
    }
}
