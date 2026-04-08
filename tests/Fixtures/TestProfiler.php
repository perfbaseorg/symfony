<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Fixtures;

use Perfbase\Symfony\Profiling\AbstractProfiler;
use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\Symfony\Support\PerfbaseErrorHandler;

class TestProfiler extends AbstractProfiler
{
    private bool $shouldProfile = true;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        PerfbaseClientProvider $clientProvider,
        PerfbaseErrorHandler $errorHandler,
        array $config
    ) {
        parent::__construct('test.span', $clientProvider, $errorHandler, $config, 'test', '1.2.3');
    }

    public function setShouldProfile(bool $shouldProfile): void
    {
        $this->shouldProfile = $shouldProfile;
    }

    public function exposeSetDefaultAttributes(): void
    {
        $this->setDefaultAttributes();
    }

    protected function shouldProfile(): bool
    {
        return $this->shouldProfile;
    }
}
