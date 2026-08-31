<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Route;

use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

final class InspectRoute
{
    private const SERVICE_ID = 'router';

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_route_inspect',
        description: 'Deeper diagnostic for a single Symfony route: path, methods, controller, host, loader-derived metadata, and duplicate-segment detection (catches double-prefix bugs where the same path segment appears twice due to outer prefix + sylius.resource loader).',
    )]
    public function __invoke(string $name): array
    {
        if ('' === trim($name)) {
            return Envelope::error('invalid_name', 'Argument "name" must not be empty.');
        }

        $router = $this->host->getContainer()->get(self::SERVICE_ID);
        if (!$router instanceof RouterInterface) {
            return Envelope::error('router_unavailable', 'Service "router" is not a Symfony\\Component\\Routing\\RouterInterface.');
        }

        $route = $router->getRouteCollection()->get($name);
        if (null === $route) {
            return Envelope::empty(sprintf(
                'Route "%s" not registered. Call sylius_routes_show name_prefix=... to discover similar.',
                $name,
            ));
        }

        $defaults = $route->getDefaults();
        $controller = $defaults['_controller'] ?? null;
        [$controllerClass, $action] = $this->splitController(\is_string($controller) ? $controller : null);

        $duplicateSegments = $this->detectDuplicateSegments($route->getPath());
        $warnings = [];
        if ([] !== $duplicateSegments) {
            $warnings[] = sprintf(
                'Path segment%s %s appear%s more than once — check outer prefix vs sylius.resource loader.',
                \count($duplicateSegments) > 1 ? 's' : '',
                implode(', ', array_map(static fn (string $s): string => '"' . $s . '"', $duplicateSegments)),
                \count($duplicateSegments) > 1 ? '' : 's',
            );
        }

        return Envelope::items(
            [[
                'name' => $name,
                'path' => $route->getPath(),
                'methods' => $route->getMethods(),
                'host' => $route->getHost(),
                'controller' => $controller,
                'controller_class' => $controllerClass,
                'action' => $action,
                'defaults' => array_diff_key($defaults, ['_controller' => true]),
                'requirements' => $route->getRequirements(),
                'duplicate_segments' => $duplicateSegments,
                'warnings' => $warnings,
            ]],
            null,
            [] === $warnings
                ? sprintf('Route "%s" looks clean.', $name)
                : sprintf('Route "%s" has %d warning(s) — see warnings field.', $name, \count($warnings)),
        );
    }

    /**
     * @return list<string>
     */
    private function detectDuplicateSegments(string $path): array
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $seg): bool => '' !== $seg));

        $counts = [];
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '{')) {
                continue;
            }

            $counts[$segment] = ($counts[$segment] ?? 0) + 1;
        }

        $duplicates = [];
        foreach ($counts as $segment => $count) {
            if ($count > 1) {
                $duplicates[] = $segment;
            }
        }

        return $duplicates;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitController(?string $controller): array
    {
        if (null === $controller) {
            return [null, null];
        }

        if (str_contains($controller, '::')) {
            [$class, $method] = explode('::', $controller, 2);

            return [$class, $method];
        }

        return [$controller, '__invoke'];
    }
}
