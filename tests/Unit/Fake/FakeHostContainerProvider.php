<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Fake;

use Psr\Container\ContainerInterface;
use Sylius\MateExtension\Kernel\HostContainerProvider;

final class FakeHostContainerProvider implements HostContainerProvider
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }
}
