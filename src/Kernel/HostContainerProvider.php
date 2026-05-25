<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Kernel;

use Psr\Container\ContainerInterface;

interface HostContainerProvider
{
    public function getContainer(): ContainerInterface;
}
