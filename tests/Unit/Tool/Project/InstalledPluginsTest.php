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

    public function testDetectsEnabledBundleNotOwnedByAnyVendorPackage(): void
    {
        // The bundle class lives under this repo's own src/ (autoloaded via
        // PSR-4, not vendor/), so with kernel.project_dir pointed at the
        // sandbox, reflection resolves a real file whose path can never sit
        // under "<sandbox>/vendor/" — package_name/version must come back
        // null. Proves the "host's own code, not a vendor package" branch
        // without touching any real project files.
        file_put_contents(
            $this->sandbox . '/config/bundles.php',
            sprintf("<?php\nreturn [\n    \\%s::class => ['all' => true],\n];\n", InstalledPlugins::class),
        );

        $tool = new InstalledPlugins($this->host());

        $result = ($tool)();

        self::assertCount(1, $result['items']);
        self::assertSame(InstalledPlugins::class, $result['items'][0]['bundle_class']);
        self::assertNull($result['items'][0]['package_name']);
        self::assertNull($result['items'][0]['package_version']);
    }

    public function testEmptyWhenBundlesPhpMissing(): void
    {
        $tool = new InstalledPlugins($this->host());

        $result = ($tool)();

        self::assertSame([], $result['items']);
        self::assertNull($result['sylius_version']);
    }

    public function testExposesSyliusVersionAndPackagesFromComposerLock(): void
    {
        file_put_contents($this->sandbox . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'sylius/sylius', 'version' => '2.2.6', 'type' => 'library'],
                ['name' => 'sylius/refund-plugin', 'version' => '1.5.0', 'type' => 'sylius-plugin'],
                ['name' => 'symfony/console', 'version' => 'v7.1.0', 'type' => 'library'],
            ],
        ], \JSON_THROW_ON_ERROR));

        $tool = new InstalledPlugins($this->host());

        $result = ($tool)();

        self::assertSame('2.2.6', $result['sylius_version']);
        self::assertCount(2, $result['sylius_packages']);
        $names = array_column($result['sylius_packages'], 'name');
        self::assertContains('sylius/sylius', $names);
        self::assertContains('sylius/refund-plugin', $names);
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
