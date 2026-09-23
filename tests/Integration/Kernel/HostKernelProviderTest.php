<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Kernel;

use Sylius\MateExtension\Kernel\HostKernelProvider;
use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\TestApplication\Kernel;
use Symfony\Component\DependencyInjection\Container;

final class HostKernelProviderTest extends IntegrationTestCase
{
    public function testBootsTheHostKernelInDevDebugMode(): void
    {
        $kernel = self::host()->getKernel();

        self::assertInstanceOf(Kernel::class, $kernel);
        self::assertSame('dev', $kernel->getEnvironment());
        self::assertTrue($kernel->isDebug());
    }

    public function testExposesTheCompiledContainerWithProjectDir(): void
    {
        $container = self::host()->getContainer();

        self::assertInstanceOf(Container::class, $container);
        self::assertTrue($container->hasParameter('kernel.project_dir'));
        self::assertSame(self::testApplicationDir(), $container->getParameter('kernel.project_dir'));
    }

    public function testResolvesKernelClassFromMateHostKernelEnv(): void
    {
        $_SERVER['MATE_HOST_KERNEL'] = Kernel::class;

        try {
            $provider = new HostKernelProvider();
            self::assertInstanceOf(Kernel::class, $provider->getKernel());
            $provider->shutdown();
        } finally {
            unset($_SERVER['MATE_HOST_KERNEL']);
        }
    }
}
