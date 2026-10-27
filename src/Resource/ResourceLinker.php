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

/**
 * Cross-links resources, grids and routes by their resource URIs.
 *
 * A route belongs to the resource whose ResourceController it calls
 * (`<app>.controller.<name>::…Action`, only when that alias is registered) and
 * to the grid named in its `_sylius.grid` default; a grid belongs to the
 * resource whose model is its `driver.options.class`. Resources declared with
 * `#[AsResource]` route through the operation system instead of a
 * `<app>.controller.<name>` service, so they get no route links.
 *
 * The index is built once per host container and dropped as soon as
 * HostKernelProvider hands out another instance (reboot after a stale
 * container dump, shutdown), so links never outlive the registry they came from.
 */
final class ResourceLinker
{
    private const CONTROLLER_PATTERN = '/^([^.:]+)\.controller\.([^.:]+)::/';

    private ?ContainerInterface $indexedContainer = null;

    /**
     * @var array{
     *     models: array<string, string>,
     *     grids: array<string, array<string, mixed>>,
     *     route_aliases: array<string, string>,
     *     route_grids: array<string, string>,
     * }|null
     */
    private ?array $index = null;

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

        $grids = [];
        foreach ($index['grids'] as $name => $definition) {
            if (null !== $model && $this->gridClass($definition) === $model) {
                $grids[] = GridDefinition::URI_PREFIX . $name;
            }
        }

        $routes = [];
        foreach ($index['route_aliases'] as $name => $routeAlias) {
            if ($routeAlias === $alias) {
                $routes[] = InspectRoute::URI_PREFIX . $name;
            }
        }

        sort($grids);
        sort($routes);

        return ['grids' => $grids, 'routes' => $routes];
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array{resource: ?string, routes: list<string>}
     */
    public function forGrid(string $name, array $definition): array
    {
        $index = $this->index();

        $routes = [];
        foreach ($index['route_grids'] as $routeName => $grid) {
            if ($grid === $name) {
                $routes[] = InspectRoute::URI_PREFIX . $routeName;
            }
        }
        sort($routes);

        $class = $this->gridClass($definition);
        $aliases = null === $class ? [] : array_keys($index['models'], $class, true);
        sort($aliases);

        // Several aliases may share one model class (a plugin re-registering a
        // core resource under its own prefix): the alias whose routes render
        // this grid wins, otherwise the first alias by name.
        $alias = $aliases[0] ?? null;
        foreach ($routes as $routeUri) {
            $routeAlias = $index['route_aliases'][substr($routeUri, \strlen(InspectRoute::URI_PREFIX))] ?? null;
            if (null !== $routeAlias && \in_array($routeAlias, $aliases, true)) {
                $alias = $routeAlias;
                break;
            }
        }

        return [
            'resource' => null === $alias ? null : InspectResource::URI_PREFIX . $alias,
            'routes' => $routes,
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
            'resource' => null === $alias ? null : InspectResource::URI_PREFIX . $alias,
            'grid' => null !== $grid && isset($index['grids'][$grid]) ? GridDefinition::URI_PREFIX . $grid : null,
        ];
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
     *     grids: array<string, array<string, mixed>>,
     *     route_aliases: array<string, string>,
     *     route_grids: array<string, string>,
     * }
     */
    private function index(): array
    {
        $container = $this->host->getContainer();
        if (null !== $this->index && $container === $this->indexedContainer) {
            return $this->index;
        }

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

        $this->indexedContainer = $container;

        return $this->index = [
            'models' => $models,
            'grids' => GridDefinitionsReader::read($container) ?? [],
            'route_aliases' => $routeAliases,
            'route_grids' => $routeGrids,
        ];
    }

    /**
     * Links are additive to the payloads: a host without a usable router
     * (missing service, routing resource that fails to load — the situation
     * the agent is diagnosing) yields no route links rather than an error.
     *
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
