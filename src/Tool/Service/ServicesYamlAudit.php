<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Service;

use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\Component\Yaml\Yaml;

final class ServicesYamlAudit
{
    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_services_yaml_audit',
        description: 'Audit host config/services.yaml + every imported config/services/*.yaml. Catches the explicit-def-vs-App\\:-glob conflict (postmortem #1+#2): when a service has an explicit definition under a path that is also covered by the App\\: glob, the glob silently overrides the explicit def. Returns per-conflict fix hint.',
    )]
    public function __invoke(): array
    {
        return Envelope::guard(fn (): array => $this->doAudit());
    }

    /**
     * @return array<string, mixed>
     */
    private function doAudit(): array
    {
        if (!class_exists(Yaml::class)) {
            return Envelope::error('yaml_unavailable', 'symfony/yaml component is required.');
        }

        $projectRoot = HostProjectDir::resolve($this->host);
        $rootFile = $projectRoot . '/config/services.yaml';
        if (!is_file($rootFile)) {
            return Envelope::error('services_yaml_missing', sprintf('No %s found.', $rootFile));
        }

        $files = array_merge([$rootFile], $this->resolveImports($rootFile));

        $appGlob = null;
        $instanceofOverrides = [];
        $explicitDefs = [];

        foreach ($files as $file) {
            $parsed = Yaml::parseFile($file);
            if (!\is_array($parsed) || !\is_array($parsed['services'] ?? null)) {
                continue;
            }

            foreach ($parsed['services'] as $key => $entry) {
                if (!\is_string($key) || !\is_array($entry)) {
                    continue;
                }

                if ('_instanceof' === $key) {
                    $instanceofOverrides = array_merge($instanceofOverrides, $entry);

                    continue;
                }

                if ('_defaults' === $key) {
                    continue;
                }

                $resource = $entry['resource'] ?? null;
                if (\is_string($resource)) {
                    if (null === $appGlob && !str_contains($key, 'Controller')) {
                        $exclude = $entry['exclude'] ?? [];
                        $appGlob = [
                            'service_pattern' => $key,
                            'resource' => $resource,
                            'exclude' => \is_string($exclude) || \is_array($exclude) ? $exclude : [],
                            'file' => $this->stripRoot($file, $projectRoot),
                        ];
                    }

                    continue;
                }

                $explicitDefs[] = [
                    'id' => $key,
                    'class' => $entry['class'] ?? $key,
                    'arguments_set' => isset($entry['arguments']),
                    'tags' => $this->flattenTagNames($entry['tags'] ?? []),
                    'file' => $this->stripRoot($file, $projectRoot),
                ];
            }
        }

        $conflicts = [];
        if (null !== $appGlob) {
            foreach ($explicitDefs as $def) {
                $conflict = $this->detectConflict($def, $appGlob, $projectRoot);
                if (null !== $conflict) {
                    $conflicts[] = $conflict;
                }
            }
        }

        $envelope = Envelope::items(
            $explicitDefs,
            null,
            [] === $conflicts
                ? sprintf('Audited %d explicit def(s) across %d file(s); no glob conflicts.', \count($explicitDefs), \count($files))
                : sprintf('Found %d conflict(s); see "conflicts" field. Skill must refuse "done" until resolved.', \count($conflicts)),
        );

        $envelope['app_glob'] = $appGlob;
        $envelope['instanceof_overrides'] = $instanceofOverrides;
        $envelope['conflicts'] = $conflicts;
        $envelope['files_audited'] = array_map(fn (string $f): string => $this->stripRoot($f, $projectRoot), $files);

        return $envelope;
    }

    /**
     * @param array<string, mixed>                                      $def
     * @param array{service_pattern: string, resource: string, exclude: array<int|string, mixed>|string, file: string} $appGlob
     *
     * @return ?array<string, mixed>
     */
    private function detectConflict(array $def, array $appGlob, string $projectRoot): ?array
    {
        $classRaw = $def['class'] ?? $def['id'];
        $class = \is_string($classRaw) ? $classRaw : '';
        if (!str_contains($class, '\\')) {
            return null;
        }

        $pattern = rtrim($appGlob['service_pattern'], '\\') . '\\';
        if (!str_starts_with($class, $pattern)) {
            return null;
        }

        $relative = substr($class, \strlen($pattern));
        $segments = explode('\\', $relative);
        array_pop($segments);
        $subdir = '' === ($joined = implode('/', $segments)) ? '' : $joined . '/';
        $excludeNeedle = sprintf('../src/%s', $subdir);

        $excludes = $this->normalizeExcludes($appGlob['exclude']);
        if ($this->excludeCovers($excludes, $excludeNeedle, $relative)) {
            return null;
        }

        return [
            'service_id' => $def['id'],
            'class' => $class,
            'explicit_def_file' => $def['file'],
            'would_be_overridden_by_glob' => $appGlob['service_pattern'] . ' → ' . $appGlob['resource'],
            'fix' => sprintf("Add '%s' to %s exclude in %s.", rtrim($excludeNeedle, '/') . '/', $appGlob['service_pattern'], $appGlob['file']),
        ];
    }

    /**
     * @param array<int|string, mixed>|string $exclude
     *
     * @return list<string>
     */
    private function normalizeExcludes(array|string $exclude): array
    {
        if (\is_string($exclude)) {
            return [$exclude];
        }

        $out = [];
        foreach ($exclude as $item) {
            if (\is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $excludes
     */
    private function excludeCovers(array $excludes, string $directoryNeedle, string $relativeClass): bool
    {
        foreach ($excludes as $excluded) {
            $normalized = rtrim($excluded, '/');
            if ('' === $normalized) {
                continue;
            }

            if (str_starts_with(rtrim($directoryNeedle, '/'), $normalized)) {
                return true;
            }

            if (str_ends_with($normalized, '/' . $relativeClass . '.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolveImports(string $rootFile): array
    {
        $parsed = Yaml::parseFile($rootFile);
        $imports = \is_array($parsed) && \is_array($parsed['imports'] ?? null) ? $parsed['imports'] : [];

        $files = [];
        foreach ($imports as $import) {
            $resource = \is_array($import) ? ($import['resource'] ?? null) : (\is_string($import) ? $import : null);
            if (!\is_string($resource)) {
                continue;
            }

            $files = array_merge($files, $this->expandResource(\dirname($rootFile), $resource));
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function expandResource(string $baseDir, string $resource): array
    {
        $absolute = str_starts_with($resource, '/') ? $resource : $baseDir . '/' . $resource;

        if (is_dir($absolute)) {
            $matches = [];
            foreach (glob($absolute . '/*.{yml,yaml}', \GLOB_BRACE) ?: [] as $file) {
                $matches[] = $file;
            }

            return $matches;
        }

        if (is_file($absolute)) {
            return [$absolute];
        }

        $glob = glob($absolute) ?: [];

        return array_values(array_filter($glob, 'is_file'));
    }

    /**
     * @return list<string>
     */
    private function flattenTagNames(mixed $tags): array
    {
        if (!\is_array($tags)) {
            return [];
        }

        $names = [];
        foreach ($tags as $tag) {
            if (\is_string($tag)) {
                $names[] = $tag;
            } elseif (\is_array($tag) && \is_string($tag['name'] ?? null)) {
                $names[] = $tag['name'];
            }
        }

        return $names;
    }

    private function stripRoot(string $absolute, string $root): string
    {
        if (str_starts_with($absolute, $root . '/')) {
            return substr($absolute, \strlen($root) + 1);
        }

        return $absolute;
    }
}
