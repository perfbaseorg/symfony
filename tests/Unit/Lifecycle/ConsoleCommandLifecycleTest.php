<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Unit\Lifecycle;

use Perfbase\Symfony\Lifecycle\ConsoleCommandLifecycle;
use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\Symfony\Support\PerfbaseErrorHandler;
use Perfbase\Symfony\Tests\Fixtures\RecordingPerfbaseClient;
use PHPUnit\Framework\TestCase;

class ConsoleCommandLifecycleTest extends TestCase
{
    public function test_console_command_profiles_and_sets_exit_code(): void
    {
        $client = new RecordingPerfbaseClient();
        $lifecycle = new ConsoleCommandLifecycle(
            'app:sync',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->setExitCode(2);
        $lifecycle->setException(new \RuntimeException('failed'));
        $lifecycle->stopProfiling();

        self::assertSame(['cli'], $client->startedSpans);
        self::assertSame('console', $client->attributes['source']);
        self::assertSame('app:sync', $client->attributes['action']);
        self::assertSame('2', $client->attributes['exit_code']);
        self::assertSame('failed', $client->attributes['exception']);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_excluded_console_command_is_not_profiled(): void
    {
        $client = new RecordingPerfbaseClient();
        $config = $this->config();
        $config['exclude']['console'] = ['app:*'];

        $lifecycle = new ConsoleCommandLifecycle(
            'app:sync',
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

    public function test_disabled_console_profiling_is_not_started(): void
    {
        $client = new RecordingPerfbaseClient();
        $config = $this->config();
        $config['enabled'] = false;

        $lifecycle = new ConsoleCommandLifecycle(
            'app:sync',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $config,
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();

        self::assertSame([], $client->startedSpans);
    }

    public function test_malformed_console_filters_are_treated_as_empty(): void
    {
        $client = new RecordingPerfbaseClient();
        $config = $this->config();
        $config['include']['console'] = 'not-an-array';

        $lifecycle = new ConsoleCommandLifecycle(
            'app:sync',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $config,
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();

        self::assertSame([], $client->startedSpans);
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
}
