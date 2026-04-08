<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Unit\Lifecycle;

use Perfbase\Symfony\Lifecycle\HttpRequestLifecycle;
use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\Symfony\Support\PerfbaseErrorHandler;
use Perfbase\Symfony\Tests\Fixtures\RecordingPerfbaseClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

class HttpRequestLifecycleTest extends TestCase
{
    public function test_start_and_stop_profile_http_request(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        $client = new RecordingPerfbaseClient();
        $lifecycle = new HttpRequestLifecycle(
            Request::create('https://example.com/articles/42?token=secret', 'GET'),
            '/articles/{id}',
            'app_article',
            'App\\Controller\\ArticleController::show',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3',
            $this->makeTokenStorage('user-123')
        );

        $lifecycle->startProfiling();
        $lifecycle->setResponseStatusCode(201);
        $lifecycle->stopProfiling();

        self::assertSame(['http.GET./articles/{id}'], $client->startedSpans);
        self::assertSame(['http.GET./articles/{id}'], $client->stoppedSpans);
        self::assertSame(1, $client->submitCalls);
        self::assertSame('GET /articles/{id}', $client->attributes['action']);
        self::assertSame('https://example.com/articles/42', $client->attributes['http_url']);
        self::assertSame('201', $client->attributes['http_status_code']);
        self::assertSame('user-123', $client->attributes['user_id']);
        self::assertSame('http', $client->attributes['source']);
    }

    public function test_excluded_http_request_is_not_profiled(): void
    {
        $client = new RecordingPerfbaseClient();
        $config = $this->config();
        $config['exclude']['http'] = ['/admin*'];

        $lifecycle = new HttpRequestLifecycle(
            Request::create('/admin/users', 'GET'),
            null,
            null,
            null,
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $config,
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->stopProfiling();

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_disabled_http_profiling_is_not_started(): void
    {
        $client = new RecordingPerfbaseClient();
        $config = $this->config();
        $config['enabled'] = false;

        $lifecycle = new HttpRequestLifecycle(
            Request::create('/articles/42', 'GET'),
            '/articles/{id}',
            'app_article',
            null,
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $config,
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();

        self::assertFalse($lifecycle->hasStarted());
        self::assertSame([], $client->startedSpans);
    }

    public function test_exception_attribute_is_submitted(): void
    {
        $client = new RecordingPerfbaseClient();
        $lifecycle = new HttpRequestLifecycle(
            Request::create('/articles/42', 'GET'),
            '/articles/{id}',
            'app_article',
            null,
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->setException(new \RuntimeException('boom'));
        $lifecycle->stopProfiling();

        self::assertSame('boom', $client->attributes['exception']);
    }

    public function test_string_token_user_does_not_break_trace_submission(): void
    {
        $returnType = (new \ReflectionMethod(TokenInterface::class, 'getUser'))->getReturnType();
        if ($returnType instanceof \ReflectionNamedType && $returnType->getName() === \Symfony\Component\Security\Core\User\UserInterface::class) {
            $this->markTestSkipped('Installed Symfony version does not allow string token users.');
        }

        $client = new RecordingPerfbaseClient();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn('anon.');

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $lifecycle = new HttpRequestLifecycle(
            Request::create('/articles/42', 'GET'),
            '/articles/{id}',
            'app_article',
            null,
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3',
            $tokenStorage
        );

        $lifecycle->startProfiling();
        $lifecycle->stopProfiling();

        self::assertFalse($lifecycle->hasStarted());
        self::assertSame(['http.GET./articles/{id}'], $client->startedSpans);
        self::assertSame(['http.GET./articles/{id}'], $client->stoppedSpans);
        self::assertSame(1, $client->submitCalls);
        self::assertArrayNotHasKey('user_id', $client->attributes);
    }

    private function makeProvider(RecordingPerfbaseClient $client): PerfbaseClientProvider
    {
        return new PerfbaseClientProvider(
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
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'enabled' => true,
            'sample_rate' => 1.0,
            'include' => [
                'http' => ['*'],
                'console' => ['*'],
            ],
            'exclude' => [
                'http' => [],
                'console' => [],
            ],
        ];
    }

    private function makeTokenStorage(string $userId): TokenStorageInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(new InMemoryUser($userId, 'password'));

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        return $tokenStorage;
    }
}
