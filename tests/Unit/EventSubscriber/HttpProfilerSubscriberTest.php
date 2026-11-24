<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Unit\EventSubscriber;

use Perfbase\Symfony\EventSubscriber\HttpProfilerSubscriber;
use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\Symfony\Support\PerfbaseErrorHandler;
use Perfbase\Symfony\Tests\Fixtures\RecordingPerfbaseClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

class HttpProfilerSubscriberTest extends TestCase
{
    public function test_subscribed_events_are_declared(): void
    {
        self::assertSame([
            KernelEvents::REQUEST => ['onRequest', 0],
            KernelEvents::RESPONSE => ['onResponse', -256],
            KernelEvents::EXCEPTION => ['onException', 256],
            KernelEvents::TERMINATE => 'onTerminate',
        ], HttpProfilerSubscriber::getSubscribedEvents());
    }

    public function test_response_exception_and_terminate_noop_without_lifecycle(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $request = Request::create('/health', 'GET');

        $subscriber->onResponse(new ResponseEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok', 200)));
        $subscriber->onException(new ExceptionEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('boom')));
        $subscriber->onTerminate(new TerminateEvent($this->makeKernel(), $request, new Response('ok', 200)));

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_request_without_router_or_route_uses_path_fallback(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client, null);
        $request = Request::create('https://example.com/raw/path?secret=1', 'GET');
        $request->attributes->set('_route', []);
        $request->attributes->set('_controller', []);

        $subscriber->onRequest(new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate(new TerminateEvent($this->makeKernel(), $request, new Response('ok', 200)));

        self::assertSame(['http'], $client->startedSpans);
        self::assertSame('GET /raw/path', $client->attributes['action']);
        self::assertSame('https://example.com/raw/path?secret=REDACTED', $client->attributes['http_url']);
    }

    public function test_disabled_profiling_skips_route_resolution_and_lifecycle(): void
    {
        $client = new RecordingPerfbaseClient();
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('getRouteCollection');

        $subscriber = $this->makeSubscriber($client, $router, ['enabled' => false]);
        $request = Request::create('/articles/99', 'GET');
        $request->attributes->set('_route', 'app_article');

        $subscriber->onRequest(new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate(new TerminateEvent($this->makeKernel(), $request, new Response('ok', 200)));

        self::assertFalse($request->attributes->has('_perfbase_http_lifecycle'));
        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_router_failure_falls_back_gracefully(): void
    {
        $client = new RecordingPerfbaseClient();
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willThrowException(new \RuntimeException('router exploded'));

        $subscriber = $this->makeSubscriber($client, $router);
        $request = Request::create('/articles/99', 'GET');
        $request->attributes->set('_route', 'app_article');
        $request->attributes->set('_controller', 'App\\Controller\\ArticleController::show');

        $subscriber->onRequest(new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onException(new ExceptionEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('plain failure')));
        $subscriber->onTerminate(new TerminateEvent($this->makeKernel(), $request, new Response('boom', 500)));

        self::assertSame(['http'], $client->startedSpans);
        self::assertSame('plain failure', $client->attributes['exception']);
        self::assertArrayNotHasKey('http_status_code', $client->attributes);
    }

    public function test_missing_route_template_falls_back_to_route_name(): void
    {
        $client = new RecordingPerfbaseClient();
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn(new RouteCollection());

        $subscriber = $this->makeSubscriber($client, $router);
        $request = Request::create('/dashboard', 'GET');
        $request->attributes->set('_route', 'app_dashboard');

        $subscriber->onRequest(new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminate(new TerminateEvent($this->makeKernel(), $request, new Response('ok', 200)));

        self::assertSame(['http'], $client->startedSpans);
    }

    public function test_not_found_exception_is_not_submitted_by_default(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $request = Request::create('/missing', 'GET');

        $subscriber->onRequest(new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST, new Response('missing', 404)));
        $subscriber->onTerminate(new TerminateEvent($this->makeKernel(), $request, new Response('missing', 404)));

        self::assertSame('404', $client->attributes['http_status_code']);
        self::assertSame(0, $client->submitCalls);
        self::assertSame(1, $client->resetCalls);
    }

    public function test_custom_allowed_status_code_is_submitted(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client, null, [
            'profile_http_status_codes' => [200, 404],
        ]);
        $request = Request::create('/missing', 'GET');

        $subscriber->onRequest(new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST, new Response('missing', 404)));
        $subscriber->onTerminate(new TerminateEvent($this->makeKernel(), $request, new Response('missing', 404)));

        self::assertSame(1, $client->submitCalls);
        self::assertSame(0, $client->resetCalls);
    }

    public function test_server_error_status_code_is_submitted_by_default(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $request = Request::create('/explode', 'GET');

        $subscriber->onRequest(new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST, new Response('error', 500)));
        $subscriber->onTerminate(new TerminateEvent($this->makeKernel(), $request, new Response('error', 500)));

        self::assertSame('500', $client->attributes['http_status_code']);
        self::assertSame(1, $client->submitCalls);
        self::assertSame(0, $client->resetCalls);
    }

    /**
     * @param array<string, mixed> $configOverrides
     */
    private function makeSubscriber(
        RecordingPerfbaseClient $client,
        ?RouterInterface $router = null,
        array $configOverrides = []
    ): HttpProfilerSubscriber
    {
        $config = array_replace_recursive([
            'enabled' => true,
            'sample_rate' => 1.0,
            'profile_http_status_codes' => array_merge(range(200, 299), range(500, 599)),
            'include' => ['http' => ['*']],
            'exclude' => ['http' => []],
        ], $configOverrides);

        return new HttpProfilerSubscriber(
            $config,
            'test',
            '1.2.3',
            new PerfbaseClientProvider(
                [
                    'api_key' => 'test-key',
                    'api_url' => 'https://receiver.perfbase.local',
                    'flags' => 0,
                    'timeout' => 10,
                    'proxy' => null,
                ],
                new PerfbaseErrorHandler(false, false),
                static function () use ($client) {
                    return $client;
                }
            ),
            new PerfbaseErrorHandler(false, false),
            $router
        );
    }

    private function makeKernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('ok', 200);
            }
        };
    }
}
