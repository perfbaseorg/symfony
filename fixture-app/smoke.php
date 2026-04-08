<?php

declare(strict_types=1);

use Perfbase\Symfony\EventSubscriber\ConsoleProfilerSubscriber;
use Perfbase\Symfony\EventSubscriber\HttpProfilerSubscriber;
use Perfbase\Symfony\FixtureApp\FakePerfbaseClientProvider;
use Perfbase\Symfony\FixtureApp\FakeRecordingPerfbaseClient;
use Perfbase\Symfony\FixtureApp\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\TerminableInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new FakeRecordingPerfbaseClient();
FakePerfbaseClientProvider::$client = $client;

$kernel = new Kernel('test', true);
$kernel->boot();

$container = $kernel->getContainer();

$config = $container->getParameter('perfbase.config');

if (!is_array($config) || ($config['enabled'] ?? null) !== true || ($config['api_key'] ?? null) !== 'fixture-api-key') {
    fwrite(STDERR, "Unexpected perfbase configuration in fixture app.\n");
    exit(1);
}

$dispatcher = $container->get('event_dispatcher');

if (!$dispatcher instanceof EventDispatcherInterface) {
    fwrite(STDERR, "Missing event dispatcher.\n");
    exit(1);
}

$requestListeners = $dispatcher->getListeners(KernelEvents::REQUEST);
$consoleListeners = $dispatcher->getListeners(ConsoleEvents::COMMAND);

$hasHttpSubscriber = false;
foreach ($requestListeners as $listener) {
    if (is_array($listener) && ($listener[0] ?? null) instanceof HttpProfilerSubscriber) {
        $hasHttpSubscriber = true;
        break;
    }
}

$hasConsoleSubscriber = false;
foreach ($consoleListeners as $listener) {
    if (is_array($listener) && ($listener[0] ?? null) instanceof ConsoleProfilerSubscriber) {
        $hasConsoleSubscriber = true;
        break;
    }
}

if (!$hasHttpSubscriber || !$hasConsoleSubscriber) {
    fwrite(STDERR, "Perfbase subscribers are not registered in the fixture app.\n");
    exit(1);
}

$request = Request::create('https://example.test/fixture/articles/123?token=secret', 'GET');
$response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

if ($kernel instanceof TerminableInterface) {
    $kernel->terminate($request, $response);
}

$application = new Application($kernel);
$application->setAutoExit(false);
$exitCode = $application->run(
    new ArrayInput(['command' => 'perfbase:fixture']),
    new BufferedOutput()
);

if ($response->getStatusCode() !== 200 || $exitCode !== 0) {
    fwrite(STDERR, "Fixture app runtime smoke checks failed.\n");
    exit(1);
}

if ($client->startedSpans === [] || $client->submitCalls !== 2) {
    fwrite(STDERR, "Perfbase profiling did not execute in fixture app flows.\n");
    exit(1);
}

echo "Fixture app booted successfully.\n";

$kernel->shutdown();
