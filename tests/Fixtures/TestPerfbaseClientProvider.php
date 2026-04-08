<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Fixtures;

use Perfbase\Symfony\Support\PerfbaseClientProvider;
use Perfbase\SDK\Perfbase;

class TestPerfbaseClientProvider extends PerfbaseClientProvider
{
    public static ?Perfbase $client = null;

    public function __construct()
    {
    }

    public function getClient(): ?Perfbase
    {
        return self::$client;
    }
}
