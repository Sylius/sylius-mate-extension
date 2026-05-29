<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Kernel;

use Symfony\Component\DependencyInjection\Container;
use Webmozart\Assert\Assert;

final class HostProjectDir
{
    public static function resolve(HostContainerProvider $host): string
    {
        $container = $host->getContainer();
        if ($container instanceof Container && $container->hasParameter('kernel.project_dir')) {
            $projectDir = $container->getParameter('kernel.project_dir');
            Assert::string($projectDir);

            return $projectDir;
        }

        return getcwd() ?: '.';
    }
}
