<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Support;

use Symfony\Component\HttpFoundation\Request;

class SpanNaming
{
    public static function forHttp(Request $request, ?string $routeTemplate, ?string $routeName): string
    {
        $identifier = $routeTemplate;
        if ($identifier === null || $identifier === '') {
            if ($routeName !== null && $routeName !== '') {
                $identifier = $routeName;
            } else {
                $identifier = self::ensureLeadingSlash($request->getPathInfo());
            }
        } elseif ($identifier[0] !== '/') {
            $identifier = self::ensureLeadingSlash($identifier);
        }

        return sprintf('http.%s.%s', $request->getMethod(), $identifier);
    }

    public static function forConsole(string $command): string
    {
        return sprintf('console.%s', $command);
    }

    private static function ensureLeadingSlash(string $value): string
    {
        $trimmed = ltrim($value, '/');

        return '/' . $trimmed;
    }
}
