<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Support;

use Symfony\Component\HttpFoundation\Request;

class SpanNaming
{
    public static function forHttp(Request $request, ?string $routeTemplate, ?string $routeName): string
    {
        return 'http';
    }

    public static function forConsole(string $command): string
    {
        return 'cli';
    }
}
