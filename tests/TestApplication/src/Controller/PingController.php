<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\TestApplication\Controller;

use Symfony\Component\HttpFoundation\Response;

final class PingController
{
    public function __invoke(): Response
    {
        return new Response('pong');
    }
}
