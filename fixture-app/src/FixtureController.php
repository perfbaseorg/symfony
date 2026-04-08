<?php

declare(strict_types=1);

namespace Perfbase\Symfony\FixtureApp;

use Symfony\Component\HttpFoundation\Response;

class FixtureController
{
    public function show(string $id): Response
    {
        return new Response('article-' . $id, 200);
    }

    public function health(): Response
    {
        return new Response('ok', 200);
    }
}
