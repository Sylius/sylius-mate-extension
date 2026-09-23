<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Kernel\HostKernelProvider;
use Sylius\TestApplication\Kernel;

/**
 * Boots the stock Sylius test application exactly the way the Mate CLI boots a
 * host project: through HostKernelProvider, env "dev" with debug, one kernel
 * per PHP process. Tools under test see a real compiled container, so anything
 * relying on service visibility, compiler-pass output or the debug XML dump is
 * exercised for real — the unit suite's fake container cannot catch that.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?HostKernelProvider $host = null;

    protected static function host(): HostKernelProvider
    {
        if (null === self::$host) {
            self::$host = new HostKernelProvider(Kernel::class);
        }

        return self::$host;
    }

    /** kernel.project_dir of the host, i.e. where sylius/test-application is installed */
    protected static function testApplicationDir(): string
    {
        return \dirname((string) (new \ReflectionClass(Kernel::class))->getFileName(), 2);
    }
}
