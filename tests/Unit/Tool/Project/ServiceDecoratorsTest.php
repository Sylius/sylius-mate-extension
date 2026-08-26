<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Project;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Project\ServiceDecorators;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\Kernel;

final class ServiceDecoratorsTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sylius-mate-decorators-' . bin2hex(random_bytes(4));
        mkdir($this->sandbox, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->sandbox);
    }

    public function testDetectsDecoratorsOfSyliusServicesFromContainerXmlDump(): void
    {
        // decorator_class is a real, vendor-installed class (this repo's own
        // symfony/http-kernel) so the ComposerPackageResolver branch that
        // walks ReflectionClass::getFileName() against <project_dir>/vendor/
        // is exercised against real data — kernel.project_dir is pointed at
        // this repo's actual root for that reason.
        $dumpFile = $this->sandbox . '/container.xml';
        file_put_contents($dumpFile, sprintf(
            <<<'XML'
                <?xml version="1.0" ?>
                <container xmlns="http://symfony.com/schema/dic/services"
                    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                    xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
                    <services>
                        <service id="acme.decorator.product_availability_checker" class="%s" decorates="sylius.checker.inventory.availability" decoration-priority="5"/>
                        <service id="acme.some_unrelated_decorator" class="%s" decorates="acme.unrelated_service"/>
                        <service id="acme.plain_service" class="%s"/>
                    </services>
                </container>
                XML,
            Kernel::class,
            Kernel::class,
            Kernel::class,
        ));

        $container = new Container(new ParameterBag([
            'kernel.project_dir' => $this->realProjectDir(),
            'debug.container.dump' => $dumpFile,
        ]));

        $tool = new ServiceDecorators(new FakeHostContainerProvider($container));

        $result = ($tool)();

        self::assertCount(1, $result['items']);
        self::assertSame('sylius.checker.inventory.availability', $result['items'][0]['original_service_id']);
        self::assertSame('acme.decorator.product_availability_checker', $result['items'][0]['decorator_service_id']);
        self::assertSame(Kernel::class, $result['items'][0]['decorator_class']);
        self::assertSame('symfony/http-kernel', $result['items'][0]['decorator_package']);
        self::assertNotNull($result['items'][0]['decorator_package_version']);
        self::assertSame(5, $result['items'][0]['priority']);
    }

    public function testEmptyWhenDebugContainerDumpParameterMissing(): void
    {
        $tool = new ServiceDecorators($this->host());

        $result = ($tool)();

        self::assertSame([], $result['items']);
    }

    private function host(): FakeHostContainerProvider
    {
        return new FakeHostContainerProvider(new Container(new ParameterBag([
            'kernel.project_dir' => $this->sandbox,
        ])));
    }

    private function realProjectDir(): string
    {
        return \dirname(__DIR__, 4);
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $child = $path . '/' . $item;
            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
