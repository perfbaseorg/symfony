<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Profiling;

use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\Symfony\Support\PerfbaseErrorHandler;
use Perfbase\SDK\Perfbase;
use Perfbase\SDK\SubmitResult;

abstract class AbstractProfiler
{
    protected PerfbaseClientProvider $clientProvider;
    protected PerfbaseErrorHandler $errorHandler;

    /** @var array<string, mixed> */
    protected array $config;

    /** @var array<string, string> */
    protected array $attributes = [];

    protected string $environment;
    protected string $appVersion;
    protected string $spanName;

    private ?Perfbase $client = null;
    private bool $started = false;

    /**
     * Span names currently owned by a profiling lifecycle, per process,
     * mapped to the client that started them.
     *
     * Lifecycles of the same kind share one span name ("http", "cli"), so a
     * nested one (a command running another command) must not touch the span
     * its parent already owns: the SDK rejects the second start, and the
     * nested lifecycle would otherwise stop and submit the parent's trace.
     * @var array<string, Perfbase>
     */
    private static array $spanOwners = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        string $spanName,
        PerfbaseClientProvider $clientProvider,
        PerfbaseErrorHandler $errorHandler,
        array $config,
        string $environment,
        string $appVersion
    ) {
        $this->spanName = $spanName;
        $this->clientProvider = $clientProvider;
        $this->errorHandler = $errorHandler;
        $this->config = $config;
        $this->environment = $environment;
        $this->appVersion = $appVersion;
    }

    public function startProfiling(): void
    {
        if ($this->started) {
            return;
        }

        try {
            $this->client = $this->clientProvider->getClient();
            if ($this->client === null) {
                return;
            }

            if (isset(self::$spanOwners[$this->spanName]) && self::$spanOwners[$this->spanName] === $this->client) {
                return;
            }

            if (!$this->passesSampleRateCheck() || !$this->shouldProfile()) {
                return;
            }

            if (!$this->client->isExtensionAvailable()) {
                return;
            }

            $this->client->startTraceSpan($this->spanName);
            self::$spanOwners[$this->spanName] = $this->client;
            $this->setDefaultAttributes();
            $this->started = true;
        } catch (\Throwable $throwable) {
            $this->started = false;
            self::releaseSpan($this->spanName, $this->client);
            $this->errorHandler->handle($throwable, 'start_profiling');
        }
    }

    private static function releaseSpan(string $spanName, ?Perfbase $client): void
    {
        if ($client !== null && isset(self::$spanOwners[$spanName]) && self::$spanOwners[$spanName] === $client) {
            unset(self::$spanOwners[$spanName]);
        }
    }

    public function stopProfiling(): void
    {
        if (!$this->started || $this->client === null) {
            return;
        }

        try {
            foreach ($this->attributes as $key => $value) {
                $this->client->setAttribute($key, $value);
            }

            if (!$this->client->stopTraceSpan($this->spanName)) {
                return;
            }

            if (!$this->shouldSubmitTrace()) {
                $this->client->reset();
                return;
            }

            $result = $this->client->submitTrace();

            if (!$result->isSuccess()) {
                $this->errorHandler->handle(
                    new \RuntimeException(sprintf(
                        'Trace submission failed (%s): %s',
                        $result->getStatus(),
                        $result->getMessage()
                    )),
                    'submit_trace'
                );
            }
        } catch (\Throwable $throwable) {
            $this->errorHandler->handle($throwable, 'stop_profiling');
        } finally {
            $this->started = false;
            self::releaseSpan($this->spanName, $this->client);
        }
    }

    public function setAttribute(string $key, string $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    public function setException(\Throwable $throwable): void
    {
        $this->setAttribute('exception', $throwable->getMessage());
    }

    public function setExitCode(int $exitCode): void
    {
        $this->setAttribute('exit_code', (string) $exitCode);
    }

    public function hasStarted(): bool
    {
        return $this->started;
    }

    /**
     * @param mixed $filters
     * @return array<string>
     */
    protected function normalizeFilters($filters): array
    {
        if (!is_array($filters)) {
            return [];
        }

        return array_values(array_filter($filters, static function ($filter): bool {
            return is_string($filter) && $filter !== '';
        }));
    }

    protected function setDefaultAttributes(): void
    {
        $this->setAttributes([
            'hostname' => gethostname() ?: '',
            'environment' => $this->environment,
            'app_version' => $this->appVersion,
            'php_version' => phpversion() ?: '',
        ]);
    }

    protected function passesSampleRateCheck(): bool
    {
        $sampleRate = $this->config['sample_rate'] ?? 0.1;

        if (!is_numeric($sampleRate)) {
            throw new \RuntimeException('Configured perfbase sample_rate must be numeric.');
        }

        $sampleRate = (float) $sampleRate;

        if ($sampleRate < 0.0 || $sampleRate > 1.0) {
            throw new \RuntimeException('Configured perfbase sample_rate must be between 0.0 and 1.0.');
        }

        if ($sampleRate === 1.0) {
            return true;
        }

        if ($sampleRate === 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $sampleRate;
    }

    abstract protected function shouldProfile(): bool;

    protected function shouldSubmitTrace(): bool
    {
        return true;
    }
}
