<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Lifecycle;

use Perfbase\Symfony\Profiling\AbstractProfiler;
use Perfbase\Symfony\Support\FilterMatcher;
use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\Symfony\Support\PerfbaseErrorHandler;
use Perfbase\Symfony\Support\SpanNaming;
use Perfbase\SDK\Utils\EnvironmentUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class HttpRequestLifecycle extends AbstractProfiler
{
    private Request $request;
    private ?string $routeTemplate;
    private ?string $routeName;
    private ?string $controller;
    private ?TokenStorageInterface $tokenStorage;
    private ?int $responseStatusCode = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        Request $request,
        ?string $routeTemplate,
        ?string $routeName,
        ?string $controller,
        PerfbaseClientProvider $clientProvider,
        PerfbaseErrorHandler $errorHandler,
        array $config,
        string $environment,
        string $appVersion,
        ?TokenStorageInterface $tokenStorage = null
    ) {
        parent::__construct(
            SpanNaming::forHttp($request, $routeTemplate, $routeName),
            $clientProvider,
            $errorHandler,
            $config,
            $environment,
            $appVersion
        );

        $this->request = $request;
        $this->routeTemplate = $routeTemplate;
        $this->routeName = $routeName;
        $this->controller = $controller;
        $this->tokenStorage = $tokenStorage;
    }

    public function setResponseStatusCode(int $statusCode): void
    {
        $this->responseStatusCode = $statusCode;
        $this->setAttribute('http_status_code', (string) $statusCode);
    }

    protected function shouldProfile(): bool
    {
        if (!(bool) ($this->config['enabled'] ?? false)) {
            return false;
        }

        return FilterMatcher::passesFilters(
            $this->getRequestComponents(),
            $this->normalizeFilters($this->config['include']['http'] ?? ['*']),
            $this->normalizeFilters($this->config['exclude']['http'] ?? [])
        );
    }

    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        $actionTarget = $this->routeTemplate ?: ($this->routeName ?: $this->getRequestPath());
        $attributes = [
            'source' => 'http',
            'action' => sprintf('%s %s', $this->request->getMethod(), $actionTarget),
            'http_method' => $this->request->getMethod(),
            'http_url' => $this->request->getSchemeAndHttpHost() . $this->getRequestPath(),
            'user_ip' => (string) (EnvironmentUtils::getUserIp() ?? ''),
            'user_agent' => (string) (EnvironmentUtils::getUserUserAgent() ?? ''),
        ];

        $this->setAttributes($attributes);

        $userIdentifier = $this->resolveUserIdentifier();
        if ($userIdentifier !== null && $userIdentifier !== '') {
            $this->setAttribute('user_id', $userIdentifier);
        }
    }

    protected function shouldSubmitTrace(): bool
    {
        if ($this->responseStatusCode === null) {
            return true;
        }

        return in_array($this->responseStatusCode, $this->normalizeStatusCodes(
            $this->config['profile_http_status_codes'] ?? array_merge(range(200, 299), range(500, 599))
        ), true);
    }

    /**
     * @return array<string>
     */
    private function getRequestComponents(): array
    {
        $path = $this->getRequestPath();
        $components = [
            $path,
            sprintf('%s %s', $this->request->getMethod(), $path),
        ];

        if ($this->routeTemplate !== null && $this->routeTemplate !== '') {
            $components[] = $this->routeTemplate;
            $components[] = sprintf('%s %s', $this->request->getMethod(), $this->routeTemplate);
        }

        if ($this->routeName !== null && $this->routeName !== '') {
            $components[] = $this->routeName;
        }

        if ($this->controller !== null && $this->controller !== '') {
            $components[] = $this->controller;
        }

        return array_values(array_unique($components));
    }

    private function getRequestPath(): string
    {
        $path = $this->request->getPathInfo();

        return $path === '' ? '/' : $path;
    }

    private function resolveUserIdentifier(): ?string
    {
        if ($this->tokenStorage === null) {
            return null;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return null;
        }

        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return null;
        }

        return $user->getUserIdentifier();
    }

    /**
     * @param mixed $statusCodes
     * @return array<int>
     */
    private function normalizeStatusCodes($statusCodes): array
    {
        if (!is_array($statusCodes)) {
            return array_merge(range(200, 299), range(500, 599));
        }

        $normalized = [];

        foreach ($statusCodes as $statusCode) {
            if (is_int($statusCode)) {
                $normalized[] = $statusCode;
                continue;
            }

            if (is_string($statusCode) && ctype_digit($statusCode)) {
                $normalized[] = (int) $statusCode;
            }
        }

        return array_values(array_unique($normalized));
    }
}
