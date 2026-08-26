<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Project;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tests\Unit\Fake\FakePackagistClient;
use Sylius\MateExtension\Tool\Project\PluginCompatibility;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class PluginCompatibilityTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sylius-mate-compat-' . bin2hex(random_bytes(4));
        mkdir($this->sandbox, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->sandbox);
    }

    public function testInstalledVersionAlreadySatisfyingTargetSkipsPackagistLookup(): void
    {
        $this->writeLock([
            'acme/compatible-plugin' => ['version' => '3.0.0', 'type' => 'sylius-plugin'],
        ]);
        $this->writePluginComposerJson('acme/compatible-plugin', ['sylius/sylius' => '^2.0']);

        $tool = new PluginCompatibility($this->host(), new FakePackagistClient());

        $result = ($tool)(target_sylius_version: '2.0');

        self::assertCount(1, $result['items']);
        $item = $result['items'][0];
        self::assertSame('acme/compatible-plugin', $item['package_name']);
        self::assertSame('^2.0', $item['sylius_constraint']);
        self::assertTrue($item['supports_target']);
        self::assertNull($item['packagist_lookup']);
        self::assertNull($item['latest_compatible_version']);
    }

    public function testIncompatibleInstalledVersionLooksUpLatestCompatibleOnPackagist(): void
    {
        $this->writeLock([
            'acme/incompatible-plugin' => ['version' => '1.2.0', 'type' => 'sylius-plugin'],
        ]);
        $this->writePluginComposerJson('acme/incompatible-plugin', ['sylius/sylius' => '^1.13']);

        $packagist = new FakePackagistClient([
            'acme/incompatible-plugin' => [
                ['version' => 'v1.2.0', 'require' => ['sylius/sylius' => '^1.13']],
                ['version' => 'v2.0.0', 'require' => ['sylius/sylius' => '^2.0']],
                ['version' => 'v2.1.0', 'require' => ['sylius/sylius' => '^2.0']],
                ['version' => 'v2.2.0-beta1', 'require' => ['sylius/sylius' => '^2.0']],
                ['version' => 'dev-main', 'require' => ['sylius/sylius' => '^2.0']],
                ['version' => 'v3.0.0', 'require' => ['sylius/sylius' => '^3.0']],
            ],
        ]);

        $tool = new PluginCompatibility($this->host(), $packagist);

        $result = ($tool)(target_sylius_version: '2.0');

        $item = $result['items'][0];
        self::assertFalse($item['supports_target']);
        self::assertTrue($item['packagist_lookup']);
        // v2.1.0 is the highest STABLE release whose constraint is satisfied
        // by 2.0.0 — v2.2.0-beta1 (unstable) and v3.0.0 (^3.0, doesn't match)
        // must both be excluded.
        self::assertSame('v2.1.0', $item['latest_compatible_version']);
    }

    public function testPackagistLookupFailureIsReportedNotFatal(): void
    {
        $this->writeLock([
            'acme/unreachable-plugin' => ['version' => '1.0.0', 'type' => 'sylius-plugin'],
        ]);
        $this->writePluginComposerJson('acme/unreachable-plugin', ['sylius/sylius' => '^1.13']);

        $tool = new PluginCompatibility($this->host(), new FakePackagistClient());

        $result = ($tool)(target_sylius_version: '2.0');

        $item = $result['items'][0];
        self::assertFalse($item['supports_target']);
        self::assertFalse($item['packagist_lookup']);
        self::assertNull($item['latest_compatible_version']);
    }

    public function testUnknownConstraintWhenPluginComposerJsonMissing(): void
    {
        $this->writeLock([
            'acme/no-composer-json-plugin' => ['version' => '1.0.0', 'type' => 'sylius-plugin'],
        ]);

        $tool = new PluginCompatibility($this->host(), new FakePackagistClient());

        $result = ($tool)(target_sylius_version: '2.0');

        $item = $result['items'][0];
        self::assertNull($item['sylius_constraint']);
        self::assertNull($item['supports_target']);
    }

    public function testIgnoresNonPluginPackages(): void
    {
        $this->writeLock([
            'symfony/console' => ['version' => 'v7.1.0', 'type' => 'library'],
        ]);

        $tool = new PluginCompatibility($this->host(), new FakePackagistClient());

        $result = ($tool)(target_sylius_version: '2.0');

        self::assertSame([], $result['items']);
    }

    /**
     * @param array<string, array{version: string, type: string}> $packages
     */
    private function writeLock(array $packages): void
    {
        $entries = [];
        foreach ($packages as $name => $info) {
            $entries[] = ['name' => $name, 'version' => $info['version'], 'type' => $info['type']];
        }

        file_put_contents($this->sandbox . '/composer.lock', json_encode(['packages' => $entries], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $require
     */
    private function writePluginComposerJson(string $packageName, array $require): void
    {
        $dir = $this->sandbox . '/vendor/' . $packageName;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/composer.json', json_encode(['name' => $packageName, 'require' => $require], \JSON_THROW_ON_ERROR));
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
