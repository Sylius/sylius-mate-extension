<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Resource;

use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Tool\Grid\GridDefinition;
use Sylius\MateExtension\Tool\Resource\InspectResource;
use Sylius\MateExtension\Tool\Route\InspectRoute;
use Sylius\Resource\Metadata\MetadataInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

final class ResourceLinker
{
    private const GRIDS_PARAMETER = 'sylius.grids_definitions';

    private const CONTROLLER_PATTERN = '/^([a-z0-9_]+)\.controller\.([a-z0-9_]+)::/';

    /** @var array<string, string>|null */
    private ?array $modelClasses = null;

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array{grids: list<string>, routes: list<string>}
     */
    public function forResource(string $alias): array
    {
        $model = $this->modelClasses()[$alias] ?? null;

        $grids = [];
        foreach ($this->gridDefinitions() as $name => $definition) {
            if (null !== $model && $this->gridClass($definition) === $model) {
                $grids[] = GridDefinition::URI_PREFIX . $name;
            }
        }

        $routes = [];
        foreach ($this->routes() as $name => $route) {
            if ($this->resourceAliasOf($route) === $alias) {
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
        $class = $this->gridClass($definition);
        $alias = null === $class ? false : array_search($class, $this->modelClasses(), true);

        $routes = [];
        foreach ($this->routes() as $routeName => $route) {
            if ($this->gridOf($route) === $name) {
                $routes[] = InspectRoute::URI_PREFIX . $routeName;
            }
        }

        sort($routes);

        return [
            'resource' => \is_string($alias) ? InspectResource::URI_PREFIX . $alias : null,
            'routes' => $routes,
        ];
    }

    /**
     * @return array{resource: ?string, grid: ?string}
     */
    public function forRoute(Route $route): array
    {
        $alias = $this->resourceAliasOf($route);
        $grid = $this->gridOf($route);

        return [
            'resource' => null === $alias ? null : InspectResource::URI_PREFIX . $alias,
            'grid' => null !== $grid && isset($this->gridDefinitions()[$grid]) ? GridDefinition::URI_PREFIX . $grid : null,
        ];
    }

    private function resourceAliasOf(Route $route): ?string
    {
        $controller = $route->getDefault('_controller');
        if (!\is_string($controller) || 1 !== preg_match(self::CONTROLLER_PATTERN, $controller, $matches)) {
            return null;
        }

        $alias = $matches[1] . '.' . $matches[2];

        return isset($this->modelClasses()[$alias]) ? $alias : null;
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
     * @return array<string, string>
     */
    private function modelClasses(): array
    {
        if (null !== $this->modelClasses) {
            return $this->modelClasses;
        }

        $models = [];
        foreach (ResourceRegistryReader::read($this->host->getContainer())?->getAll() ?? [] as $metadata) {
            if ($metadata instanceof MetadataInterface && $metadata->hasClass('model')) {
                $models[$metadata->getAlias()] = $metadata->getClass('model');
            }
        }

        return $this->modelClasses = $models;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function gridDefinitions(): array
    {
        $container = $this->host->getContainer();
        if (!$container instanceof Container || !$container->hasParameter(self::GRIDS_PARAMETER)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $definitions */
        $definitions = $container->getParameter(self::GRIDS_PARAMETER);

        return $definitions;
    }

    /**
     * @return array<string, Route>
     */
    private function routes(): array
    {
        $router = $this->host->getContainer()->get('router');

        return $router instanceof RouterInterface ? $router->getRouteCollection()->all() : [];
    }
}
