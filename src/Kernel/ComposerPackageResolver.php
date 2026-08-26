<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Kernel;

/**
 * Shared, registry-free composer.lock / vendor introspection used by any
 * tool that needs to know "what package owns this class" or "what's
 * installed" — no hardcoded plugin list, works for any package including
 * unknown/private ones.
 */
final class ComposerPackageResolver
{
    /**
     * @return array<string, array{version: string, type: ?string}>
     */
    public static function readLock(string $projectDir): array
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
    public static function resolve(string $class, string $projectDir, array $lock): ?array
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
}
