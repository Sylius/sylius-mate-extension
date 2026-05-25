<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Route;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Route\ShowRoute;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

final class ShowRouteTest extends TestCase
{
    public function testReturnsControllerAndActionForNamedRoute(): void
    {
        $tool = new ShowRoute($this->host([
            'sylius_shop_product_show' => new Route('/products/{slug}', [
                '_controller' => 'Sylius\\ShopController::show',
            ], methods: ['GET']),
        ]));

        $result = ($tool)('sylius_shop_product_show');

        self::assertCount(1, $result['items']);
        self::assertSame('Sylius\\ShopController', $result['items'][0]['controller_class']);
        self::assertSame('show', $result['items'][0]['action']);
        self::assertSame(['GET'], $result['items'][0]['methods']);
        self::assertSame('/products/{slug}', $result['items'][0]['path']);
    }

    public function testReturnsEmptyEnvelopeWhenRouteUnknown(): void
    {
        $tool = new ShowRoute($this->host([
            'a' => new Route('/a'),
        ]));

        $result = ($tool)('missing');

        self::assertSame([], $result['items']);
        self::assertStringContainsString('not registered', $result['note']);
    }

    public function testListsRoutesByPrefix(): void
    {
        $tool = new ShowRoute($this->host([
            'sylius_shop_a' => new Route('/a'),
            'sylius_admin_b' => new Route('/b'),
            'app_c' => new Route('/c'),
        ]));

        $result = ($tool)(null, 'sylius_');

        $names = array_column($result['items'], 'name');
        self::assertSame(['sylius_admin_b', 'sylius_shop_a'], $names);
    }

    public function testSplitsInvokableControllerToAction(): void
    {
        $tool = new ShowRoute($this->host([
            'r' => new Route('/r', ['_controller' => 'App\\InvokableAction']),
        ]));

        $result = ($tool)('r');

        self::assertSame('App\\InvokableAction', $result['items'][0]['controller_class']);
        self::assertSame('__invoke', $result['items'][0]['action']);
    }

    /**
     * @param array<string, Route> $routes
     */
    private function host(array $routes): FakeHostContainerProvider
    {
        $collection = new RouteCollection();
        foreach ($routes as $name => $route) {
            $collection->add($name, $route);
        }

        $router = new Router(
            new class ($collection) implements \Symfony\Component\Config\Loader\LoaderInterface {
                public function __construct(private readonly RouteCollection $collection)
                {
                }

                public function load(mixed $resource, ?string $type = null): RouteCollection
                {
                    return $this->collection;
                }

                public function supports(mixed $resource, ?string $type = null): bool
                {
                    return true;
                }

                public function getResolver(): \Symfony\Component\Config\Loader\LoaderResolverInterface
                {
                    throw new \LogicException();
                }

                public function setResolver(\Symfony\Component\Config\Loader\LoaderResolverInterface $resolver): void
                {
                }
            },
            'placeholder',
            [],
            new RequestContext(),
        );

        $container = new Container();
        $container->set('router', $router);

        return new FakeHostContainerProvider($container);
    }
}
