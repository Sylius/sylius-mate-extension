<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Grid;

use Sylius\Component\Core\Model\Product;
use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tool\Grid\ListGrids;

final class ListGridsTest extends IntegrationTestCase
{
    public function testDescribesFixtureGridFromCompiledDefinitions(): void
    {
        $result = (new ListGrids(self::host()))('mate_test_');

        self::assertCount(1, $result['items']);
        $grid = $result['items'][0];
        self::assertSame('mate_test_book', $grid['name']);
        self::assertSame('doctrine/orm', $grid['driver']);
        self::assertSame(Product::class, $grid['resource_class']);
        self::assertSame(['code', 'name'], $grid['fields']);
        self::assertSame(['code'], $grid['filters']);
        self::assertSame(['main', 'item'], $grid['actions']);
    }

    public function testSeesStockSyliusAdminGrids(): void
    {
        $result = (new ListGrids(self::host()))('sylius_admin_product', 100);

        self::assertContains('sylius_admin_product', array_column($result['items'], 'name'));
    }
}
