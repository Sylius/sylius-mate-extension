<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Project;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Project\InstalledPlugins;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class InstalledPluginsTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sylius-mate-plugins-' . bin2hex(random_bytes(4));
        mkdir($this->sandbox . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->sandbox);
    }

    public function testDetectsKnownMsiPlugin(): void
    {
        file_put_contents(
            $this->sandbox . '/config/bundles.php',
            "<?php\nreturn [\n    Sylius\\MultiSourceInventoryPlugin\\SyliusMultiSourceInventoryPlugin::class => ['all' => true],\n];\n",
        );

        $tool = new InstalledPlugins($this->host());

        $result = ($tool)();

        self::assertCount(1, $result['items']);
        self::assertSame('sylius/multi-source-inventory-plugin', $result['items'][0]['name']);
        self::assertNotEmpty($result['items'][0]['decorates']);
    }

    public function testEmptyWhenBundlesPhpMissing(): void
    {
        $tool = new InstalledPlugins($this->host());

        $result = ($tool)();

        self::assertSame([], $result['items']);
    }

    private function host(): FakeHostContainerProvider
    {
        return new FakeHostContainerProvider(new Container(new ParameterBag([
            'kernel.project_dir' => $this->sandbox,
        ])));
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
