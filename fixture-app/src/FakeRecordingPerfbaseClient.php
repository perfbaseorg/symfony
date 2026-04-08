<?php

declare(strict_types=1);

namespace Perfbase\Symfony\FixtureApp;

use Perfbase\SDK\Perfbase;
use Perfbase\SDK\SubmitResult;

class FakeRecordingPerfbaseClient extends Perfbase
{
    /** @var array<string, string> */
    public array $attributes = [];

    /** @var array<int, string> */
    public array $startedSpans = [];

    /** @var array<int, string> */
    public array $stoppedSpans = [];

    public bool $extensionAvailable = true;
    public bool $stopResult = true;
    public int $submitCalls = 0;
    public ?SubmitResult $submitResult = null;

    public function __construct()
    {
    }

    public function __destruct()
    {
    }

    public function startTraceSpan(string $spanName, array $attributes = []): void
    {
        $this->startedSpans[] = $spanName;
    }

    public function stopTraceSpan(string $spanName): bool
    {
        $this->stoppedSpans[] = $spanName;

        return $this->stopResult;
    }

    public function submitTrace(): SubmitResult
    {
        $this->submitCalls++;

        return $this->submitResult ?? SubmitResult::success();
    }

    public function reset()
    {
    }

    public function isExtensionAvailable(): bool
    {
        return $this->extensionAvailable;
    }

    public function setAttribute(string $key, string $value): void
    {
        $this->attributes[$key] = $value;
    }
}
