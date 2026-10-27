<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Resource;

use Sylius\MateExtension\Resource\ResourceLinker;
use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Webmozart\Assert\Assert;

final class ResourceLinkerTest extends IntegrationTestCase
{
    public function testLinksStockResourceToItsGridsAndRoutes(): void
    {
        $links = $this->linker()->forResource('sylius.product');

        self::assertContains('sylius://grid/sylius_admin_product', $links['grids']);
        self::assertContains('sylius://route/sylius_admin_product_index', $links['routes']);
        self::assertContains('sylius://route/sylius_shop_product_show', $links['routes']);
        self::assertNotContains('sylius://route/sylius_admin_product_variant_index', $links['routes']);
    }

    public function testLinksProjectOwnedResourceToItsGrid(): void
    {
        $links = $this->linker()->forResource('mate_test.book');

        self::assertSame(['sylius://grid/mate_test_book'], $links['grids']);
    }

    public function testLinksGridBackToItsResourceAndIndexRoute(): void
    {
        $links = $this->linker()->forGrid('sylius_admin_product', $this->gridDefinition('sylius_admin_product'));

        self::assertSame('sylius://resource/sylius.product', $links['resource']);
        self::assertContains('sylius://route/sylius_admin_product_index', $links['routes']);
    }

    public function testLinksRouteToItsResourceAndGrid(): void
    {
        $links = $this->linker()->forRoute($this->route('sylius_admin_product_index'));

        self::assertSame(['resource' => 'sylius://resource/sylius.product', 'grid' => 'sylius://grid/sylius_admin_product'], $links);
    }

    public function testRouteWithoutGridLinksOnlyItsResource(): void
    {
        $links = $this->linker()->forRoute($this->route('sylius_shop_product_show'));

        self::assertSame(['resource' => 'sylius://resource/sylius.product', 'grid' => null], $links);
    }

    public function testRouteOutsideResourceControllerHasNoLinks(): void
    {
        $links = $this->linker()->forRoute($this->route('mate_test_ping'));

        self::assertSame(['resource' => null, 'grid' => null], $links);
    }

    private function linker(): ResourceLinker
    {
        return new ResourceLinker(self::host());
    }

    /**
     * @return array<string, mixed>
     */
    private function gridDefinition(string $name): array
    {
        $container = self::host()->getContainer();
        Assert::isInstanceOf($container, Container::class);
        $definitions = $container->getParameter('sylius.grids_definitions');
        Assert::isArray($definitions);
        Assert::isArray($definitions[$name]);

        return $definitions[$name];
    }

    private function route(string $name): Route
    {
        $router = self::host()->getContainer()->get('router');
        Assert::isInstanceOf($router, RouterInterface::class);
        $route = $router->getRouteCollection()->get($name);
        Assert::notNull($route);

        return $route;
    }
}
