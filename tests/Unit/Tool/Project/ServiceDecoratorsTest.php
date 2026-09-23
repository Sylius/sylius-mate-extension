<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Project;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Project\ServiceDecorators;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\HttpKernel;
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

    public function testReconstructsDecorationChainFromContainerXmlDump(): void
    {
        // The dump mirrors what DecoratorServicePass leaves behind: no
        // `decorates=`, a container.decorator tag on the OUTERMOST decorator
        // only, `<id>.inner` aliases pointing at the next decorator and the
        // original definition moved to the innermost `.inner` id.
        // decorator classes are real, vendor-installed classes (this repo's
        // own symfony/http-kernel) so the ComposerPackageResolver branch that
        // walks ReflectionClass::getFileName() against <project_dir>/vendor/
        // is exercised against real data.
        $dumpFile = $this->sandbox . '/container.xml';
        file_put_contents($dumpFile, sprintf(
            <<<'XML'
                <?xml version="1.0" ?>
                <container xmlns="http://symfony.com/schema/dic/services"
                    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                    xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
                    <services>
                        <service id="acme.outer_checker" class="%1$s">
                            <tag name="container.decorator" id="sylius.checker.inventory.availability" inner="acme.inner_checker.inner"/>
                            <argument type="service" id="acme.inner_checker"/>
                        </service>
                        <service id="acme.outer_checker.inner" alias="acme.inner_checker"/>
                        <service id="acme.inner_checker" class="%2$s">
                            <argument type="service" id="acme.inner_checker.inner"/>
                        </service>
                        <service id="acme.inner_checker.inner" class="Sylius\Component\Inventory\Checker\AvailabilityChecker"/>
                        <service id="sylius.checker.inventory.availability" alias="acme.outer_checker"/>
                        <service id="acme.some_unrelated_decorator" class="%1$s">
                            <tag name="container.decorator" id="acme.unrelated_service" inner="acme.some_unrelated_decorator.inner"/>
                            <argument type="service" id="acme.some_unrelated_decorator.inner"/>
                        </service>
                        <service id="acme.some_unrelated_decorator.inner" class="%1$s"/>
                        <service id="acme.plain_service" class="%1$s"/>
                    </services>
                </container>
                XML,
            Kernel::class,
            HttpKernel::class,
        ));

        $container = new Container(new ParameterBag([
            'kernel.project_dir' => $this->realProjectDir(),
            'debug.container.dump' => $dumpFile,
        ]));

        $tool = new ServiceDecorators(new FakeHostContainerProvider($container));

        $result = ($tool)();

        self::assertCount(2, $result['items']);

        [$outer, $inner] = $result['items'];
        self::assertSame('sylius.checker.inventory.availability', $outer['original_service_id']);
        self::assertSame('acme.outer_checker', $outer['decorator_service_id']);
        self::assertSame(Kernel::class, $outer['decorator_class']);
        self::assertSame('symfony/http-kernel', $outer['decorator_package']);
        self::assertNotNull($outer['decorator_package_version']);
        self::assertSame(1, $outer['chain_position']);
        self::assertSame(2, $outer['chain_length']);
        self::assertSame('Sylius\\Component\\Inventory\\Checker\\AvailabilityChecker', $outer['original_class']);

        self::assertSame('acme.inner_checker', $inner['decorator_service_id']);
        self::assertSame(HttpKernel::class, $inner['decorator_class']);
        self::assertSame(2, $inner['chain_position']);
        self::assertSame(2, $inner['chain_length']);
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
