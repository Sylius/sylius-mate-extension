<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Fake;

use Psr\Container\ContainerInterface;
use Sylius\MateExtension\Kernel\HostContainerProvider;

final class FakeHostContainerProvider implements HostContainerProvider
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?string $rootDir = null,
    ) {
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getRootDir(): string
    {
        return $this->rootDir ?? (getcwd() ?: '.');
    }
}
