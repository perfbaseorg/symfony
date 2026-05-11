<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Integration;

use Perfbase\Symfony\Tests\Fixtures\RecordingPerfbaseClient;
use Perfbase\Symfony\Tests\Fixtures\TestKernel;
use Perfbase\Symfony\Tests\Fixtures\TestPerfbaseClientProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class ConsoleProfilerSubscriberTest extends TestCase
{
    private ?KernelInterface $kernel = null;

    protected function tearDown(): void
    {
        TestPerfbaseClientProvider::$client = null;

        if ($this->kernel !== null) {
            $this->kernel->shutdown();
            $this->kernel = null;
        }
    }

    public function test_console_command_terminate_flow_profiles_command(): void
    {
        $client = new RecordingPerfbaseClient();
        TestPerfbaseClientProvider::$client = $client;

        $dispatcher = $this->bootKernel()->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $command = new Command('app:sync');
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $dispatcher->dispatch(new ConsoleCommandEvent($command, $input, $output), 'console.command');
        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, $output, 0), 'console.terminate');

        self::assertSame(['cli'], $client->startedSpans);
        self::assertSame('0', $client->attributes['exit_code']);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_console_error_flow_captures_exception(): void
    {
        $client = new RecordingPerfbaseClient();
        TestPerfbaseClientProvider::$client = $client;

        $dispatcher = $this->bootKernel()->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $command = new Command('app:sync');
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $dispatcher->dispatch(new ConsoleCommandEvent($command, $input, $output), 'console.command');
        $dispatcher->dispatch(new ConsoleErrorEvent($input, $output, new \RuntimeException('command failed'), $command), 'console.error');
        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, $output, 1), 'console.terminate');

        self::assertSame('command failed', $client->attributes['exception']);
        self::assertSame('1', $client->attributes['exit_code']);
        self::assertSame(1, $client->submitCalls);
    }

    private function bootKernel(): KernelInterface
    {
        if ($this->kernel === null) {
            $this->kernel = new TestKernel();
            $this->kernel->boot();
        }

        return $this->kernel;
    }
}
