<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Project;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\ComposerPackageResolver;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Output\Envelope;

#[McpTool(
    name: 'sylius_installed_plugins',
    description: 'Dynamically detect what is installed on the host project: sylius/sylius core version, every sylius/* package in composer.lock, and every bundle enabled in config/bundles.php resolved to its owning composer package. No hardcoded plugin registry — works for any plugin, including private/company ones. Pure inventory: for what decorates a sylius.*/sylius_* core service (may or may not be plugin-owned), call sylius_service_decorators instead.',
)]
final class InstalledPlugins
{
    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return Envelope::guard(fn (): array => $this->detect());
    }

    /**
     * @return array<string, mixed>
     */
    private function detect(): array
    {
        $projectDir = HostProjectDir::resolve($this->host);
        $lock = ComposerPackageResolver::readLock($projectDir);

        $activeBundles = $this->detectActiveBundles($projectDir, $lock);

        $syliusPackages = [];
        foreach ($lock as $name => $info) {
            if (str_starts_with($name, 'sylius/')) {
                $syliusPackages[] = ['name' => $name] + $info;
            }
        }

        $envelope = Envelope::items($activeBundles, null, sprintf(
            'Detected %d enabled bundle(s).',
            \count($activeBundles),
        ));

        $envelope['sylius_version'] = $lock['sylius/sylius']['version'] ?? null;
        $envelope['sylius_packages'] = $syliusPackages;

        return $envelope;
    }

    /**
     * @param array<string, array{version: string, type: ?string}> $lock
     *
     * @return list<array<string, mixed>>
     */
    private function detectActiveBundles(string $projectDir, array $lock): array
    {
        $bundlesFile = $projectDir . '/config/bundles.php';
        if (!is_file($bundlesFile)) {
            return [];
        }

        /** @var array<string, array<string, bool>>|mixed $bundles */
        $bundles = require $bundlesFile;
        if (!\is_array($bundles)) {
            return [];
        }

        $result = [];
        foreach ($bundles as $bundleClass => $envs) {
            if (!\is_string($bundleClass)) {
                continue;
            }

            $package = ComposerPackageResolver::resolve($bundleClass, $projectDir, $lock);
            $result[] = [
                'bundle_class' => $bundleClass,
                'package_name' => $package['name'] ?? null,
                'package_version' => $package['version'] ?? null,
                'package_type' => $package['type'] ?? null,
            ];
        }

        return $result;
    }
}
