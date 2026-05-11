<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Unit\Support;

use Perfbase\Symfony\Support\SpanNaming;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class SpanNamingTest extends TestCase
{
    public function test_http_prefers_route_template(): void
    {
        $request = Request::create('/articles/42', 'GET');

        self::assertSame('http', SpanNaming::forHttp($request, '/articles/{id}', 'app_article'));
    }

    public function test_http_falls_back_to_route_name_then_path(): void
    {
        $request = Request::create('/articles/42', 'POST');

        self::assertSame('http', SpanNaming::forHttp($request, null, 'app_article'));
        self::assertSame('http', SpanNaming::forHttp($request, null, null));
    }

    public function test_console_span_name_is_stable(): void
    {
        self::assertSame('cli', SpanNaming::forConsole('app:sync'));
    }

    public function test_http_adds_leading_slash_to_route_template_when_missing(): void
    {
        $request = Request::create('/articles/42', 'GET');

        self::assertSame('http', SpanNaming::forHttp($request, 'articles/{id}', null));
    }

    public function test_generated_span_names_are_sdk_safe(): void
    {
        $span = SpanNaming::forConsole('debug:router --format=json with a very long command name');

        self::assertMatchesRegularExpression('/^[A-Za-z]+$/', $span);
        self::assertLessThanOrEqual(64, strlen($span));
    }
}
