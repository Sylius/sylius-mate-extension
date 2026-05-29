<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Service;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Output\Envelope;

#[McpTool(
    name: 'sylius_services_yaml_patch_exclude',
    description: 'Idempotently add an entry to the App\\: (or detected namespace) exclude: list in config/services.yaml so explicit service defs are not silently overridden by the glob. Args: exclude (e.g. "../src/Form/Type/BackInStock"), dry_run (default false), app_namespace (default auto-detect). Returns patched body + written flag.',
)]
final class ServicesYamlPatchExclude
{
    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $exclude, bool $dry_run = false, ?string $app_namespace = null): array
    {
        if ('' === trim($exclude)) {
            return Envelope::error('invalid_exclude', 'Argument "exclude" must not be empty.');
        }

        return Envelope::guard(fn (): array => $this->patch($exclude, $dry_run, $app_namespace));
    }

    /**
     * @return array<string, mixed>
     */
    private function patch(string $exclude, bool $dryRun, ?string $appNamespace): array
    {
        $projectDir = HostProjectDir::resolve($this->host);
        $servicesYaml = $projectDir . '/config/services.yaml';

        if (!is_file($servicesYaml)) {
            return Envelope::error(
                'services_yaml_missing',
                sprintf('No services.yaml at "%s".', $servicesYaml),
                'Run sylius_project_audit to confirm baseline conventions.',
            );
        }

        $body = (string) @file_get_contents($servicesYaml);
        $namespace = $appNamespace ?? $this->detectAppNamespace($projectDir);

        $lines = preg_split('/\\r?\\n/', $body) ?: [];

        $appIdx = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\\s{4}' . preg_quote($namespace, '/') . '\\\\:/', $line)) {
                $appIdx = $i;
                break;
            }
        }

        if (null === $appIdx) {
            return Envelope::error(
                'app_namespace_block_missing',
                sprintf('No %s\\: block in services.yaml.', $namespace),
                'Add the namespace glob block first, then re-run.',
            );
        }

        $excludeIdx = null;
        for ($i = $appIdx + 1; $i < \count($lines); ++$i) {
            $line = $lines[$i];
            if (preg_match('/^\\s{0,4}\\S/', $line) && !preg_match('/^\\s{8,}/', $line)) {
                break;
            }

            if (preg_match('/^\\s{8}exclude:/', $line)) {
                $excludeIdx = $i;
                break;
            }
        }

        $normalized = "'" . ltrim($exclude, "'\"") . "'";
        $normalized = rtrim($normalized, "'\"") . "'";

        $entry = "            - " . $normalized;

        $already = false;
        if (null !== $excludeIdx) {
            for ($i = $excludeIdx + 1; $i < \count($lines); ++$i) {
                $line = $lines[$i];
                if (preg_match('/^\\s{0,8}\\S/', $line)) {
                    break;
                }

                if (str_contains($line, $exclude)) {
                    $already = true;
                    break;
                }
            }
        }

        if ($already) {
            $envelope = Envelope::items(
                [['kind' => 'services_yaml', 'suggested_path' => 'config/services.yaml', 'body' => $body]],
                null,
                sprintf('Entry "%s" already present in %s\\: exclude — no change.', $exclude, $namespace),
            );
            $envelope['written'] = false;
            $envelope['noop'] = true;

            return $envelope;
        }

        if (null === $excludeIdx) {
            array_splice($lines, $appIdx + 1, 0, ['        exclude:', $entry]);
        } else {
            $insertAt = $excludeIdx + 1;
            for ($i = $excludeIdx + 1; $i < \count($lines); ++$i) {
                $line = $lines[$i];
                if (preg_match('/^\\s{12,}-/', $line)) {
                    $insertAt = $i + 1;

                    continue;
                }

                break;
            }
            array_splice($lines, $insertAt, 0, [$entry]);
        }

        $patched = implode("\n", $lines);

        if (!$dryRun) {
            if (false === @file_put_contents($servicesYaml, $patched)) {
                return Envelope::error('write_failed', sprintf('Could not write "%s".', $servicesYaml));
            }
        }

        $envelope = Envelope::items(
            [['kind' => 'services_yaml', 'suggested_path' => 'config/services.yaml', 'body' => $patched]],
            null,
            $dryRun
                ? sprintf('Dry-run: would add "%s" to %s\\: exclude.', $exclude, $namespace)
                : sprintf('Wrote "%s" into %s\\: exclude in config/services.yaml.', $exclude, $namespace),
        );
        $envelope['written'] = !$dryRun;
        $envelope['noop'] = false;
        $envelope['app_namespace'] = $namespace;

        return $envelope;
    }

    private function detectAppNamespace(string $projectDir): string
    {
        $composerJson = $projectDir . '/composer.json';
        if (!is_file($composerJson)) {
            return 'App';
        }

        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode((string) file_get_contents($composerJson), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'App';
        }

        $autoload = $composer['autoload'] ?? [];
        $psr4 = \is_array($autoload) ? ($autoload['psr-4'] ?? []) : [];
        if (!\is_array($psr4)) {
            return 'App';
        }

        foreach ($psr4 as $namespace => $paths) {
            if (!\is_string($namespace) || '' === $namespace) {
                continue;
            }

            foreach ((array) $paths as $path) {
                if (\is_string($path) && is_file(rtrim($projectDir . '/' . $path, '/') . '/Kernel.php')) {
                    return rtrim($namespace, '\\');
                }
            }
        }

        return 'App';
    }
}
