<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;

class TestController
{
    public function __invoke(): Response
    {
        return new Response('ok', 200);
    }
}
