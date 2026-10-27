<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Resource;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Resource\ResourceLinker;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

final class ResourceLinkerTest extends TestCase
{
    public function testReindexesWhenTheHostHandsOutAnotherContainer(): void
    {
        $before = self::container(['app.book' => 'App\\Entity\\Book'], ['app_book' => 'App\\Entity\\Book']);
        $after = self::container(
            ['app.book' => 'App\\Entity\\Book', 'app.supplier' => 'App\\Entity\\Supplier'],
            ['app_book' => 'App\\Entity\\Book', 'app_supplier' => 'App\\Entity\\Supplier'],
        );
        $host = new SwitchableHost($before);
        $linker = new ResourceLinker($host);

        self::assertSame(['grids' => ['sylius://grid/app_book'], 'routes' => []], $linker->forResource('app.book'));
        self::assertSame(['grids' => [], 'routes' => []], $linker->forResource('app.supplier'));

        $host->container = $after;

        self::assertSame(['grids' => ['sylius://grid/app_supplier'], 'routes' => []], $linker->forResource('app.supplier'));
    }

    public function testHostWithoutRouterYieldsNoRouteLinks(): void
    {
        $linker = new ResourceLinker(new SwitchableHost(self::container(['app.book' => 'App\\Entity\\Book'], ['app_book' => 'App\\Entity\\Book'])));

        self::assertSame(['grids' => ['sylius://grid/app_book'], 'routes' => []], $linker->forResource('app.book'));
        self::assertSame(['resource' => 'sylius://resource/app.book', 'routes' => []], $linker->forGrid('app_book', ['driver' => ['options' => ['class' => 'App\\Entity\\Book']]]));
    }

    public function testRouterThatFailsToLoadYieldsNoRouteLinks(): void
    {
        $container = self::container(['app.book' => 'App\\Entity\\Book'], []);
        $container->set('router', new class() extends Router {
            public function __construct()
            {
            }

            public function getRouteCollection(): RouteCollection
            {
                throw new \RuntimeException('Cannot load resource "routes/app.yaml".');
            }
        });

        $links = (new ResourceLinker(new SwitchableHost($container)))->forResource('app.book');

        self::assertSame([], $links['routes']);
    }

    public function testLinksRoutesOfAliasesWithHyphensAndUppercase(): void
    {
        $container = self::container(['acme-shop.giftCard' => 'Acme\\GiftCard'], [], [
            'acme_shop_gift_card_index' => new Route('/gift-cards', ['_controller' => 'acme-shop.controller.giftCard::indexAction']),
            'acme_shop_other' => new Route('/other', ['_controller' => 'acme-shop.controller.unknown::indexAction']),
        ]);
        $linker = new ResourceLinker(new SwitchableHost($container));

        self::assertSame(['sylius://route/acme_shop_gift_card_index'], $linker->forResource('acme-shop.giftCard')['routes']);
        self::assertSame('sylius://resource/acme-shop.giftCard', $linker->forRoute(self::route($container, 'acme_shop_gift_card_index'))['resource']);
        self::assertNull($linker->forRoute(self::route($container, 'acme_shop_other'))['resource']);
    }

    public function testGridSharedByTwoAliasesLinksToTheAliasWhoseRouteRendersIt(): void
    {
        $container = self::container(
            ['app.product' => 'App\\Entity\\Product', 'sylius.product' => 'App\\Entity\\Product'],
            ['sylius_admin_product' => 'App\\Entity\\Product', 'app_product_report' => 'App\\Entity\\Product'],
            ['sylius_admin_product_index' => new Route('/admin/products', [
                '_controller' => 'sylius.controller.product::indexAction',
                '_sylius' => ['grid' => 'sylius_admin_product'],
            ])],
        );
        $linker = new ResourceLinker(new SwitchableHost($container));
        $definition = ['driver' => ['options' => ['class' => 'App\\Entity\\Product']]];

        self::assertSame('sylius://resource/sylius.product', $linker->forGrid('sylius_admin_product', $definition)['resource']);
        // No route decides: the first alias by name, deterministically.
        self::assertSame('sylius://resource/app.product', $linker->forGrid('app_product_report', $definition)['resource']);
    }

    /**
     * @param array<string, string> $models alias => model class
     * @param array<string, string> $grids  grid name => model class
     * @param array<string, Route>  $routes
     */
    private static function container(array $models, array $grids, array $routes = []): Container
    {
        $resources = [];
        foreach ($models as $alias => $model) {
            $resources[$alias] = ['driver' => 'doctrine/orm', 'classes' => ['model' => $model]];
        }

        $definitions = [];
        foreach ($grids as $name => $model) {
            $definitions[$name] = ['driver' => ['name' => 'doctrine/orm', 'options' => ['class' => $model]]];
        }

        $container = new Container();
        $container->setParameter('sylius.resources', $resources);
        $container->setParameter('sylius.grids_definitions', $definitions);

        if ([] !== $routes) {
            $collection = new RouteCollection();
            foreach ($routes as $name => $route) {
                $collection->add($name, $route);
            }
            $router = self::createStub(RouterInterface::class);
            $router->method('getRouteCollection')->willReturn($collection);
            $container->set('router', $router);
        }

        return $container;
    }

    private static function route(Container $container, string $name): Route
    {
        $router = $container->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $route = $router->getRouteCollection()->get($name);
        self::assertNotNull($route);

        return $route;
    }
}

final class SwitchableHost implements HostContainerProvider
{
    public function __construct(
        public ContainerInterface $container,
    ) {
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getRootDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
