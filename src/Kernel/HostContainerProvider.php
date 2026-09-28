<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Kernel;

use Psr\Container\ContainerInterface;

interface HostContainerProvider
{
    public function getContainer(): ContainerInterface;

    /**
     * Host project root: Mate's `%mate.root_dir%`, not the process working directory.
     */
    public function getRootDir(): string;
}
