<?php

declare(strict_types=1);

namespace Perfbase\Symfony\EventSubscriber;

use Perfbase\Symfony\Lifecycle\HttpRequestLifecycle;
use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\Symfony\Support\PerfbaseErrorHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class HttpProfilerSubscriber implements EventSubscriberInterface
{
    private const REQUEST_ATTRIBUTE = '_perfbase_http_lifecycle';

    /** @var array<string, mixed> */
    private array $config;

    private string $environment;
    private string $appVersion;
    private PerfbaseClientProvider $clientProvider;
    private PerfbaseErrorHandler $errorHandler;
    private ?RouterInterface $router;
    private ?TokenStorageInterface $tokenStorage;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config,
        string $environment,
        string $appVersion,
        PerfbaseClientProvider $clientProvider,
        PerfbaseErrorHandler $errorHandler,
        ?RouterInterface $router = null,
        ?TokenStorageInterface $tokenStorage = null
    ) {
        $this->config = $config;
        $this->environment = $environment;
        $this->appVersion = $appVersion;
        $this->clientProvider = $clientProvider;
        $this->errorHandler = $errorHandler;
        $this->router = $router;
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * @return array<string, string|array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 0],
            KernelEvents::RESPONSE => ['onResponse', -256],
            KernelEvents::EXCEPTION => ['onException', 256],
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $request = $event->getRequest();
            $lifecycle = new HttpRequestLifecycle(
                $request,
                $this->resolveRouteTemplate($request),
                $this->resolveRouteName($request),
                $this->resolveController($request),
                $this->clientProvider,
                $this->errorHandler,
                $this->config,
                $this->environment,
                $this->appVersion,
                $this->tokenStorage
            );

            $lifecycle->startProfiling();
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $lifecycle);
        } catch (\Throwable $throwable) {
            $this->errorHandler->handle($throwable, 'http_request');
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $lifecycle = $this->getLifecycle($event->getRequest());
            if ($lifecycle === null) {
                return;
            }

            $lifecycle->setResponseStatusCode($event->getResponse()->getStatusCode());
        } catch (\Throwable $throwable) {
            $this->errorHandler->handle($throwable, 'http_response');
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $lifecycle = $this->getLifecycle($event->getRequest());
            if ($lifecycle === null) {
                return;
            }

            $throwable = $event->getThrowable();
            $lifecycle->setException($throwable);

            if ($throwable instanceof HttpExceptionInterface) {
                $lifecycle->setResponseStatusCode($throwable->getStatusCode());
            }
        } catch (\Throwable $throwable) {
            $this->errorHandler->handle($throwable, 'http_exception');
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        try {
            $lifecycle = $this->getLifecycle($event->getRequest());
            if ($lifecycle === null) {
                return;
            }

            $lifecycle->stopProfiling();
        } catch (\Throwable $throwable) {
            $this->errorHandler->handle($throwable, 'http_terminate');
        }
    }

    private function getLifecycle(Request $request): ?HttpRequestLifecycle
    {
        $lifecycle = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $lifecycle instanceof HttpRequestLifecycle ? $lifecycle : null;
    }

    private function resolveRouteTemplate(Request $request): ?string
    {
        $routeName = $this->resolveRouteName($request);
        if ($routeName === null || $routeName === '' || $this->router === null) {
            return null;
        }

        try {
            $route = $this->router->getRouteCollection()->get($routeName);
            if ($route === null) {
                return null;
            }

            $path = $route->getPath();
            return $path === '' ? null : $path;
        } catch (\Throwable $throwable) {
            $this->errorHandler->handle($throwable, 'http_route_template');
            return null;
        }
    }

    private function resolveRouteName(Request $request): ?string
    {
        $routeName = $request->attributes->get('_route');

        return is_string($routeName) && $routeName !== '' ? $routeName : null;
    }

    private function resolveController(Request $request): ?string
    {
        $controller = $request->attributes->get('_controller');

        return is_string($controller) && $controller !== '' ? $controller : null;
    }
}
