<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Lifecycle;

use Perfbase\Symfony\Profiling\AbstractProfiler;
use Perfbase\Symfony\Support\FilterMatcher;
use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\Symfony\Support\PerfbaseErrorHandler;
use Perfbase\Symfony\Support\SpanNaming;

class ConsoleCommandLifecycle extends AbstractProfiler
{
    private string $command;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        string $command,
        PerfbaseClientProvider $clientProvider,
        PerfbaseErrorHandler $errorHandler,
        array $config,
        string $environment,
        string $appVersion
    ) {
        parent::__construct(
            SpanNaming::forConsole($command),
            $clientProvider,
            $errorHandler,
            $config,
            $environment,
            $appVersion
        );

        $this->command = $command;
    }

    protected function shouldProfile(): bool
    {
        if (!(bool) ($this->config['enabled'] ?? false)) {
            return false;
        }

        return FilterMatcher::passesFilters(
            [$this->command],
            $this->normalizeFilters($this->config['include']['console'] ?? ['*']),
            $this->normalizeFilters($this->config['exclude']['console'] ?? [])
        );
    }

    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        $this->setAttributes([
            'source' => 'console',
            'action' => $this->command,
        ]);
    }
}
