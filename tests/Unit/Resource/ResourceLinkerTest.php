<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Resource;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Resource\ResourceLinker;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class ResourceLinkerTest extends TestCase
{
    public function testHostWithoutRouterYieldsNoRouteLinks(): void
    {
        $linker = self::linker(self::container(['app.book' => 'App\\Entity\\Book'], ['app_book' => 'App\\Entity\\Book']));

        self::assertSame(['grids' => ['sylius://grid/app_book'], 'routes' => []], $linker->forResource('app.book'));
        self::assertSame(['resource' => 'sylius://resource/app.book', 'routes' => []], $linker->forGrid('app_book'));
    }

    public function testRouterThatFailsToLoadYieldsNoRouteLinks(): void
    {
        $container = self::container(['app.book' => 'App\\Entity\\Book'], []);
        $router = self::createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willThrowException(new \RuntimeException('Cannot load resource "routes/app.yaml".'));
        $container->set('router', $router);

        self::assertSame([], self::linker($container)->forResource('app.book')['routes']);
    }

    public function testLinksRoutesOfAliasesWithHyphensAndUppercase(): void
    {
        $index = new Route('/gift-cards', ['_controller' => 'acme-shop.controller.giftCard::indexAction']);
        $other = new Route('/other', ['_controller' => 'acme-shop.controller.unknown::indexAction']);
        $linker = self::linker(self::container(['acme-shop.giftCard' => 'Acme\\GiftCard'], [], [
            'acme_shop_gift_card_index' => $index,
            'acme_shop_other' => $other,
        ]));

        self::assertSame(['sylius://route/acme_shop_gift_card_index'], $linker->forResource('acme-shop.giftCard')['routes']);
        self::assertSame('sylius://resource/acme-shop.giftCard', $linker->forRoute($index)['resource']);
        self::assertNull($linker->forRoute($other)['resource']);
    }

    public function testGridSharedByTwoAliasesLinksToTheAliasWhoseRouteRendersIt(): void
    {
        $linker = self::linker(self::container(
            ['app.product' => 'App\\Entity\\Product', 'sylius.product' => 'App\\Entity\\Product'],
            ['sylius_admin_product' => 'App\\Entity\\Product', 'app_product_report' => 'App\\Entity\\Product'],
            ['sylius_admin_product_index' => new Route('/admin/products', [
                '_controller' => 'sylius.controller.product::indexAction',
                '_sylius' => ['grid' => 'sylius_admin_product'],
            ])],
        ));

        self::assertSame('sylius://resource/sylius.product', $linker->forGrid('sylius_admin_product')['resource']);
        self::assertSame('sylius://resource/app.product', $linker->forGrid('app_product_report')['resource']);
    }

    public function testUriSegmentsArePercentEncoded(): void
    {
        $linker = self::linker(self::container(['app.book' => 'App\\Entity\\Book'], ['app book' => 'App\\Entity\\Book']));

        self::assertSame(['sylius://grid/app%20book'], $linker->forResource('app.book')['grids']);
    }

    private static function linker(Container $container): ResourceLinker
    {
        return new ResourceLinker(new FakeHostContainerProvider($container));
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
}
