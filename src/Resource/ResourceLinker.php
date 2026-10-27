<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Resource;

use Psr\Container\ContainerInterface;
use Sylius\MateExtension\Grid\GridDefinitionsReader;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Tool\Grid\GridDefinition;
use Sylius\MateExtension\Tool\Resource\InspectResource;
use Sylius\MateExtension\Tool\Route\InspectRoute;
use Sylius\Resource\Metadata\MetadataInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

final class ResourceLinker
{
    private const CONTROLLER_PATTERN = '/^([^.:]+)\.controller\.([^.:]+)::/';

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array{grids: list<string>, routes: list<string>}
     */
    public function forResource(string $alias): array
    {
        $index = $this->index();
        $model = $index['models'][$alias] ?? null;

        $grids = null === $model ? [] : array_keys($index['grid_classes'], $model, true);
        $routes = array_keys($index['route_aliases'], $alias, true);
        sort($grids);
        sort($routes);

        return [
            'grids' => self::uris(GridDefinition::URI_PREFIX, $grids),
            'routes' => self::uris(InspectRoute::URI_PREFIX, $routes),
        ];
    }

    /**
     * @return array{resource: ?string, routes: list<string>}
     */
    public function forGrid(string $name): array
    {
        $index = $this->index();

        $routes = array_keys($index['route_grids'], $name, true);
        sort($routes);

        $alias = null;
        foreach ($routes as $route) {
            if (isset($index['route_aliases'][$route])) {
                $alias = $index['route_aliases'][$route];
                break;
            }
        }

        if (null === $alias) {
            $class = $index['grid_classes'][$name] ?? null;
            $aliases = null === $class ? [] : array_keys($index['models'], $class, true);
            sort($aliases);
            $alias = $aliases[0] ?? null;
        }

        return [
            'resource' => null === $alias ? null : ResourceUri::build(InspectResource::URI_PREFIX, $alias),
            'routes' => self::uris(InspectRoute::URI_PREFIX, $routes),
        ];
    }

    /**
     * @return array{resource: ?string, grid: ?string}
     */
    public function forRoute(Route $route): array
    {
        $index = $this->index();
        $alias = $this->resourceAliasOf($route, $index['models']);
        $grid = $this->gridOf($route);

        return [
            'resource' => null === $alias ? null : ResourceUri::build(InspectResource::URI_PREFIX, $alias),
            'grid' => null !== $grid && \array_key_exists($grid, $index['grid_classes']) ? ResourceUri::build(GridDefinition::URI_PREFIX, $grid) : null,
        ];
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function uris(string $prefix, array $names): array
    {
        return array_map(static fn (string $name): string => ResourceUri::build($prefix, $name), $names);
    }

    /**
     * @param array<string, string> $models
     */
    private function resourceAliasOf(Route $route, array $models): ?string
    {
        $controller = $route->getDefault('_controller');
        if (!\is_string($controller) || 1 !== preg_match(self::CONTROLLER_PATTERN, $controller, $matches)) {
            return null;
        }

        $alias = $matches[1] . '.' . $matches[2];

        return isset($models[$alias]) ? $alias : null;
    }

    private function gridOf(Route $route): ?string
    {
        $sylius = $route->getDefault('_sylius');
        $grid = \is_array($sylius) ? ($sylius['grid'] ?? null) : null;

        return \is_string($grid) ? $grid : null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function gridClass(array $definition): ?string
    {
        $driver = $definition['driver'] ?? null;
        $class = \is_array($driver) && \is_array($driver['options'] ?? null) ? ($driver['options']['class'] ?? null) : null;

        return \is_string($class) ? $class : null;
    }

    /**
     * @return array{
     *     models: array<string, string>,
     *     grid_classes: array<string, ?string>,
     *     route_aliases: array<string, string>,
     *     route_grids: array<string, string>,
     * }
     */
    private function index(): array
    {
        $container = $this->host->getContainer();

        $models = [];
        foreach (ResourceRegistryReader::read($container)?->getAll() ?? [] as $metadata) {
            if ($metadata instanceof MetadataInterface && $metadata->hasClass('model')) {
                $models[$metadata->getAlias()] = $metadata->getClass('model');
            }
        }

        $routeAliases = [];
        $routeGrids = [];
        foreach ($this->routes($container) as $name => $route) {
            $alias = $this->resourceAliasOf($route, $models);
            if (null !== $alias) {
                $routeAliases[$name] = $alias;
            }

            $grid = $this->gridOf($route);
            if (null !== $grid) {
                $routeGrids[$name] = $grid;
            }
        }

        return [
            'models' => $models,
            'grid_classes' => array_map($this->gridClass(...), GridDefinitionsReader::read($container) ?? []),
            'route_aliases' => $routeAliases,
            'route_grids' => $routeGrids,
        ];
    }

    /**
     * @return array<string, Route>
     */
    private function routes(ContainerInterface $container): array
    {
        if (!$container->has('router')) {
            return [];
        }

        try {
            $router = $container->get('router');

            return $router instanceof RouterInterface ? $router->getRouteCollection()->all() : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
