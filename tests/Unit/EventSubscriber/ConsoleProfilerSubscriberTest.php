<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Unit\EventSubscriber;

use Perfbase\Symfony\EventSubscriber\ConsoleProfilerSubscriber;
use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\Symfony\Support\PerfbaseErrorHandler;
use Perfbase\Symfony\Tests\Fixtures\RecordingPerfbaseClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ConsoleProfilerSubscriberTest extends TestCase
{
    public function test_subscribed_events_are_declared(): void
    {
        self::assertSame([
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::ERROR => 'onError',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ], ConsoleProfilerSubscriber::getSubscribedEvents());
    }

    public function test_null_command_noops_cleanly(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $subscriber->onCommand(new ConsoleCommandEvent(null, $input, $output));

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_error_and_terminate_without_lifecycle_noop_cleanly(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $command = new Command('app:sync');
        $output = new BufferedOutput();

        $subscriber->onError(new ConsoleErrorEvent(new ArrayInput([]), $output, new \RuntimeException('boom'), $command));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, new ArrayInput([]), $output, 0));

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_command_without_name_falls_back_to_unknown(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $command = new Command();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertSame(['cli'], $client->startedSpans);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_disabled_profiling_does_not_track_or_start_command(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client, false);
        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $command = new Command('app:sync');

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));

        $lifecycles = new \ReflectionProperty(ConsoleProfilerSubscriber::class, 'lifecycles');
        $lifecycles->setAccessible(true);
        self::assertSame([], $lifecycles->getValue($subscriber));

        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_nested_command_does_not_take_over_the_parents_span(): void
    {
        // A command that runs another command with a fresh input starts a
        // second lifecycle while the first is still profiling. Both use the
        // "cli" span, and the SDK refuses to start a span that is already
        // active, so only the outermost command may own it.
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $output = new BufferedOutput();
        $outer = new Command('app:outer');
        $inner = new Command('app:inner');
        $outerInput = new ArrayInput([]);
        $innerInput = new ArrayInput([]);

        $subscriber->onCommand(new ConsoleCommandEvent($outer, $outerInput, $output));
        $subscriber->onCommand(new ConsoleCommandEvent($inner, $innerInput, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($inner, $innerInput, $output, 0));
        $subscriber->onTerminate(new ConsoleTerminateEvent($outer, $outerInput, $output, 0));

        self::assertSame(['cli'], $client->startedSpans);
        self::assertSame(['cli'], $client->stoppedSpans);
        self::assertSame(1, $client->submitCalls);
        self::assertSame('app:outer', $client->attributes['action'] ?? null);
    }

    public function test_span_is_released_when_a_lifecycle_finishes(): void
    {
        // Lifecycles run one after the other on the same client must each
        // get the span.
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $command = new Command('app:sync');

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));
        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertSame(['cli', 'cli'], $client->startedSpans);
        self::assertSame(2, $client->submitCalls);
    }

    private function makeSubscriber(RecordingPerfbaseClient $client, bool $enabled = true): ConsoleProfilerSubscriber
    {
        return new ConsoleProfilerSubscriber(
            [
                'enabled' => $enabled,
                'sample_rate' => 1.0,
                'include' => ['console' => ['*']],
                'exclude' => ['console' => []],
            ],
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
            new PerfbaseErrorHandler(false, false)
        );
    }
}
