<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Service;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Output\Envelope;
use Symfony\Component\Yaml\Yaml;

#[McpTool(
    name: 'sylius_services_yaml_profile',
    description: 'Inspect the host project DI profile (defaults autowire/autoconfigure/public, _instanceof overrides, app resource glob + excludes, controller glob). Use once per session to know how to register a new class so it ends up in the container.',
)]
final class ServicesYamlProfile
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
        if (!class_exists(Yaml::class)) {
            return Envelope::error('yaml_unavailable', 'symfony/yaml component is required to parse the host services file.');
        }

        $projectRoot = HostProjectDir::resolve($this->host);
        $path = $this->locateServicesYaml($projectRoot);
        if (null === $path) {
            return Envelope::error(
                'services_yaml_missing',
                sprintf('No config/services.yaml or config/services.php found in "%s".', $projectRoot),
                'Sylius-Standard ships config/services.yaml; check the project root parameter kernel.project_dir.',
            );
        }

        if (str_ends_with($path, '.php')) {
            return Envelope::items(
                [['kind' => 'unsupported_format', 'path' => $path, 'detail' => 'PHP-based services config not introspected — open the file manually.']],
                null,
                'Host uses PHP-based services config; this tool only parses YAML.',
            );
        }

        $parsed = Yaml::parseFile($path);
        if (!\is_array($parsed)) {
            return Envelope::error('parse_failed', sprintf('Could not parse %s as YAML.', $path));
        }

        $services = \is_array($parsed['services'] ?? null) ? $parsed['services'] : [];
        $defaults = \is_array($services['_defaults'] ?? null) ? $services['_defaults'] : [];
        $instanceof = \is_array($services['_instanceof'] ?? null) ? $services['_instanceof'] : [];

        $appGlob = null;
        $controllerGlob = null;
        foreach ($services as $key => $entry) {
            if (!\is_array($entry) || !\is_string($key)) {
                continue;
            }

            $resource = $entry['resource'] ?? null;
            if (!\is_string($resource)) {
                continue;
            }

            if (str_contains($key, 'Controller')) {
                $controllerGlob = ['service_pattern' => $key, 'resource' => $resource];

                continue;
            }

            if (null === $appGlob) {
                $appGlob = [
                    'service_pattern' => $key,
                    'resource' => $resource,
                    'exclude' => $entry['exclude'] ?? null,
                ];
            }
        }

        $envelope = Envelope::items([], null, sprintf('Profile read from %s.', $this->stripRoot($path, $projectRoot)));
        $envelope['profile'] = [
            'services_yaml' => $this->stripRoot($path, $projectRoot),
            'defaults' => $defaults,
            'instanceof_overrides' => $instanceof,
            'app_glob' => $appGlob,
            'controller_glob' => $controllerGlob,
        ];

        return $envelope;
    }

    private function locateServicesYaml(string $root): ?string
    {
        foreach (['/config/services.yaml', '/config/services.yml', '/config/services.php'] as $candidate) {
            if (is_file($root . $candidate)) {
                return $root . $candidate;
            }
        }

        return null;
    }

    private function stripRoot(string $absolute, string $root): string
    {
        if (str_starts_with($absolute, $root . '/')) {
            return substr($absolute, \strlen($root) + 1);
        }

        return $absolute;
    }
}
