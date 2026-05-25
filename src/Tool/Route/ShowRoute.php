<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Route;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;
use Symfony\Component\Routing\RouterInterface;

#[McpTool(
    name: 'sylius_routes_show',
    description: 'Resolve a Symfony route by name and return controller, action, path, methods and defaults. Use to verify any route referenced from code or templates actually exists. If "name" is omitted, lists routes whose name contains the optional "name_prefix" filter.',
)]
final class ShowRoute
{
    private const SERVICE_ID = 'router';

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $name = null, ?string $name_prefix = null, int $limit = 50, ?string $cursor = null): array
    {
        $router = $this->host->getContainer()->get(self::SERVICE_ID);
        if (!$router instanceof RouterInterface) {
            return Envelope::error('router_unavailable', 'Service "router" is not a Symfony\\Component\\Routing\\RouterInterface.');
        }

        $collection = $router->getRouteCollection();

        if (null !== $name) {
            $route = $collection->get($name);
            if (null === $route) {
                return Envelope::empty(sprintf(
                    'Route "%s" not registered. Call this tool with name_prefix to discover similar names.',
                    $name,
                ));
            }

            return Envelope::items([$this->describe($name, $route)]);
        }

        $items = [];
        foreach ($collection->all() as $routeName => $route) {
            if (null !== $name_prefix && !str_starts_with($routeName, $name_prefix)) {
                continue;
            }

            $items[] = $this->describe($routeName, $route);
        }

        usort($items, static fn (array $a, array $b) => $a['name'] <=> $b['name']);
        $page = Envelope::paginate($items, $limit, $cursor);

        if ([] === $page['slice']) {
            return Envelope::empty(sprintf(
                'No routes matched%s.',
                null !== $name_prefix ? sprintf(' prefix "%s"', $name_prefix) : '',
            ));
        }

        return Envelope::items($page['slice'], $page['cursor']);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $name, \Symfony\Component\Routing\Route $route): array
    {
        $defaults = $route->getDefaults();
        $controller = $defaults['_controller'] ?? null;

        [$controllerClass, $action] = $this->splitController(\is_string($controller) ? $controller : null);

        return [
            'name' => $name,
            'path' => $route->getPath(),
            'methods' => $route->getMethods(),
            'controller' => $controller,
            'controller_class' => $controllerClass,
            'action' => $action,
            'host' => $route->getHost(),
            'defaults' => array_diff_key($defaults, ['_controller' => true]),
            'requirements' => $route->getRequirements(),
        ];
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
