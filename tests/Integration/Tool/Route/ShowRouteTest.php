<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Route;

use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tests\TestApplication\Controller\PingController;
use Sylius\MateExtension\Tool\Route\ShowRoute;

final class ShowRouteTest extends IntegrationTestCase
{
    public function testResolvesFixtureRouteWithInvokableController(): void
    {
        $result = (new ShowRoute(self::host()))('mate_test_ping');

        self::assertCount(1, $result['items']);
        $route = $result['items'][0];
        self::assertSame('/mate-test/ping', $route['path']);
        self::assertSame(['GET'], $route['methods']);
        self::assertSame(PingController::class, $route['controller_class']);
        self::assertSame('__invoke', $route['action']);
    }

    public function testResolvesStockSyliusAdminRoute(): void
    {
        $result = (new ShowRoute(self::host()))('sylius_admin_product_index');

        self::assertCount(1, $result['items']);
        $route = $result['items'][0];
        self::assertStringStartsWith('/admin/', $route['path']);
        self::assertNotNull($route['controller_class']);
    }

    public function testListsRoutesByPrefix(): void
    {
        $result = (new ShowRoute(self::host()))(null, 'mate_test_');

        self::assertSame(['mate_test_double_prefix', 'mate_test_ping'], array_column($result['items'], 'name'));
    }

    public function testReportsUnknownRouteAsEmpty(): void
    {
        $result = (new ShowRoute(self::host()))('mate_test_missing');

        self::assertSame([], $result['items']);
        self::assertStringContainsString('not registered', $result['note']);
    }
}
