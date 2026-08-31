<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Project;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Sylius\MateExtension\Kernel\ComposerPackageResolver;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Kernel\PackagistClient;
use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;

final class PluginCompatibility
{
    public function __construct(
        private readonly HostContainerProvider $host,
        private readonly PackagistClient $packagist,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_plugin_compatibility',
        description: 'For every sylius-plugin-type package in composer.lock, check whether its INSTALLED version supports target_sylius_version (reads the sylius/sylius constraint straight from that plugin\'s own vendor/<pkg>/composer.json, offline) and — when it does not — look up Packagist for the newest stable released version that does. Use before bumping sylius/sylius: sylius_installed_plugins gives you the current core version in the same call as the plugin list; this tool tells you, per plugin, whether the bump is safe as-is or which plugin version you need first. Packagist lookups are best-effort network calls (repo.packagist.org) — a failed lookup sets packagist_lookup=false, it does not fail the whole call.',
    )]
    public function __invoke(string $target_sylius_version): array
    {
        return Envelope::guard(fn (): array => $this->check($target_sylius_version));
    }

    /**
     * @return array<string, mixed>
     */
    private function check(string $targetVersion): array
    {
        if (!class_exists(Semver::class)) {
            return Envelope::error('semver_unavailable', 'composer/semver is required.');
        }

        $normalizedTarget = $this->normalizeTargetVersion($targetVersion);

        $projectDir = HostProjectDir::resolve($this->host);
        $lock = ComposerPackageResolver::readLock($projectDir);

        $plugins = [];
        foreach ($lock as $name => $info) {
            if ('sylius-plugin' !== $info['type']) {
                continue;
            }

            $constraint = $this->readSyliusConstraint($projectDir, $name);
            $supportsTarget = null !== $constraint ? Semver::satisfies($normalizedTarget, $constraint) : null;

            $entry = [
                'package_name' => $name,
                'installed_version' => $info['version'],
                'sylius_constraint' => $constraint,
                'supports_target' => $supportsTarget,
                'packagist_lookup' => null,
                'latest_compatible_version' => null,
            ];

            if (true !== $supportsTarget) {
                $lookup = $this->findLatestCompatibleVersion($name, $normalizedTarget);
                $entry['packagist_lookup'] = $lookup['ok'];
                $entry['latest_compatible_version'] = $lookup['version'];
            }

            $plugins[] = $entry;
        }

        return Envelope::items($plugins, null, sprintf(
            '%d sylius-plugin package(s) checked against Sylius %s.',
            \count($plugins),
            $normalizedTarget,
        ));
    }

    private function normalizeTargetVersion(string $target): string
    {
        // "2.0" -> "2.0.0" so Semver::satisfies() gets a concrete version, not
        // a constraint, to test the plugin's own constraint against.
        if (1 === preg_match('/^\d+\.\d+$/', $target)) {
            return $target . '.0';
        }

        return $target;
    }

    private function readSyliusConstraint(string $projectDir, string $packageName): ?string
    {
        $composerJsonPath = $projectDir . '/vendor/' . $packageName . '/composer.json';
        if (!is_file($composerJsonPath)) {
            return null;
        }

        $raw = @file_get_contents($composerJsonPath);
        if (false === $raw) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $this->extractSyliusConstraint(\is_array($data['require'] ?? null) ? $data['require'] : []);
    }

    /**
     * @param array<array-key, mixed> $require
     */
    private function extractSyliusConstraint(array $require): ?string
    {
        foreach (['sylius/sylius', 'sylius/core-bundle'] as $candidate) {
            $constraint = $require[$candidate] ?? null;
            if (\is_string($constraint) && '' !== $constraint) {
                return $constraint;
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, version: ?string}
     */
    private function findLatestCompatibleVersion(string $packageName, string $targetVersion): array
    {
        $releases = $this->packagist->fetchPackageVersions($packageName);
        if (null === $releases) {
            return ['ok' => false, 'version' => null];
        }

        $candidates = [];
        foreach ($releases as $release) {
            $version = $release['version'] ?? null;
            if (!\is_string($version) || 'stable' !== VersionParser::parseStability($version)) {
                continue;
            }

            $constraint = $this->extractSyliusConstraint(\is_array($release['require'] ?? null) ? $release['require'] : []);
            if (null === $constraint || !Semver::satisfies($targetVersion, $constraint)) {
                continue;
            }

            $candidates[] = $version;
        }

        if ([] === $candidates) {
            return ['ok' => true, 'version' => null];
        }

        $sorted = Semver::rsort($candidates);

        return ['ok' => true, 'version' => $sorted[0]];
    }
}
