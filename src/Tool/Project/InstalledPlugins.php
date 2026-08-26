<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Project;

use Mcp\Capability\Attribute\McpTool;
use Psr\Container\ContainerInterface;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Output\Envelope;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

#[McpTool(
    name: 'sylius_installed_plugins',
    description: 'Dynamically detect what is installed on the host project: sylius/sylius core version, every sylius/* package in composer.lock, every bundle enabled in config/bundles.php resolved to its owning composer package, and every service in the container that decorates a sylius.*/sylius_* id (original_service_id, decorator_class, decorator_package, priority). No hardcoded plugin registry — works for any plugin, including private/company ones. Facts only: the tool does not say what a decorator implies, read decorator_class (Read/sylius_resource_inspect) to reason about that.',
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
        $lock = $this->readComposerLock($projectDir);

        $activeBundles = $this->detectActiveBundles($projectDir, $lock);
        $decorators = $this->detectDecorators($this->host->getContainer(), $projectDir, $lock);

        $syliusPackages = [];
        foreach ($lock as $name => $info) {
            if (str_starts_with($name, 'sylius/')) {
                $syliusPackages[] = ['name' => $name] + $info;
            }
        }

        $envelope = Envelope::items($activeBundles, null, sprintf(
            'Detected %d enabled bundle(s) and %d service(s) decorating a sylius.*/sylius_* id. Interpretation (what a decorator implies) is on you: read decorator_class.',
            \count($activeBundles),
            \count($decorators),
        ));

        $envelope['sylius_version'] = $lock['sylius/sylius']['version'] ?? null;
        $envelope['sylius_packages'] = $syliusPackages;
        $envelope['decorators'] = $decorators;

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

            $package = $this->resolvePackage($bundleClass, $projectDir, $lock);
            $result[] = [
                'bundle_class' => $bundleClass,
                'package_name' => $package['name'] ?? null,
                'package_version' => $package['version'] ?? null,
                'package_type' => $package['type'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Parses the container's debug XML dump (Symfony framework-bundle writes
     * one on every debug-mode boot, same source `debug:container` reads
     * decoration info from) into a fresh ContainerBuilder so decoration
     * metadata — stripped from the compiled runtime container — is
     * available. Works for decorators registered any way (YAML, PHP config,
     * a compiler pass calling setDecoratedService()), not just YAML `decorates:`.
     *
     * @param array<string, array{version: string, type: ?string}> $lock
     *
     * @return list<array<string, mixed>>
     */
    private function detectDecorators(ContainerInterface $container, string $projectDir, array $lock): array
    {
        if (!$container instanceof Container) {
            return [];
        }

        if (!class_exists(ContainerBuilder::class) || !class_exists(XmlFileLoader::class)) {
            return [];
        }

        if (!$container->hasParameter('debug.container.dump')) {
            return [];
        }

        $dumpPath = $container->getParameter('debug.container.dump');
        if (!\is_string($dumpPath) || '' === $dumpPath || !is_file($dumpPath)) {
            return [];
        }

        $builder = new ContainerBuilder();

        try {
            (new XmlFileLoader($builder, new FileLocator(\dirname($dumpPath))))->load($dumpPath);
        } catch (\Throwable) {
            return [];
        }

        $decorators = [];
        foreach ($builder->getDefinitions() as $serviceId => $definition) {
            $decorated = $definition->getDecoratedService();
            if (null === $decorated) {
                continue;
            }

            $originalId = $decorated[0] ?? null;
            if (!\is_string($originalId)) {
                continue;
            }

            if (!str_starts_with($originalId, 'sylius.') && !str_starts_with($originalId, 'sylius_')) {
                continue;
            }

            $class = $definition->getClass();
            $package = \is_string($class) ? $this->resolvePackage($class, $projectDir, $lock) : null;

            $decorators[] = [
                'original_service_id' => $originalId,
                'decorator_service_id' => $serviceId,
                'decorator_class' => $class,
                'decorator_package' => $package['name'] ?? null,
                'decorator_package_version' => $package['version'] ?? null,
                'priority' => $decorated[2] ?? 0,
            ];
        }

        usort($decorators, static fn (array $a, array $b): int => $a['original_service_id'] <=> $b['original_service_id']);

        return $decorators;
    }

    /**
     * Resolves the composer package owning a class by reflecting its source
     * file and matching it against vendor/<vendor>/<package>/ — no registry,
     * works for any installed package including unknown/private ones. Null
     * means "not under vendor/", i.e. the host project's own code.
     *
     * @param array<string, array{version: string, type: ?string}> $lock
     *
     * @return ?array{name: string, version: ?string, type: ?string}
     */
    private function resolvePackage(string $class, string $projectDir, array $lock): ?array
    {
        if (!class_exists($class) && !interface_exists($class)) {
            return null;
        }

        try {
            $file = (new \ReflectionClass($class))->getFileName();
        } catch (\Throwable) {
            return null;
        }

        if (false === $file) {
            return null;
        }

        $vendorPrefix = rtrim($projectDir, '/') . '/vendor/';
        if (!str_starts_with($file, $vendorPrefix)) {
            return null;
        }

        $segments = explode('/', substr($file, \strlen($vendorPrefix)));
        if (\count($segments) < 3) {
            return null;
        }

        $packageName = $segments[0] . '/' . $segments[1];

        return [
            'name' => $packageName,
            'version' => $lock[$packageName]['version'] ?? null,
            'type' => $lock[$packageName]['type'] ?? null,
        ];
    }

    /**
     * @return array<string, array{version: string, type: ?string}>
     */
    private function readComposerLock(string $projectDir): array
    {
        $lockFile = $projectDir . '/composer.lock';
        if (!is_file($lockFile)) {
            return [];
        }

        $raw = @file_get_contents($lockFile);
        if (false === $raw) {
            return [];
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $map = [];
        foreach (['packages', 'packages-dev'] as $key) {
            $packages = $data[$key] ?? [];
            if (!\is_array($packages)) {
                continue;
            }

            foreach ($packages as $package) {
                if (\is_array($package) && isset($package['name'], $package['version']) && \is_string($package['name']) && \is_string($package['version'])) {
                    $type = $package['type'] ?? null;
                    $map[$package['name']] = [
                        'version' => $package['version'],
                        'type' => \is_string($type) ? $type : null,
                    ];
                }
            }
        }

        return $map;
    }
}
