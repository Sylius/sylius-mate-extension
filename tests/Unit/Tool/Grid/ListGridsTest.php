<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Grid;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Grid\ListGrids;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class ListGridsTest extends TestCase
{
    public function testExtractsDriverFieldsFiltersActions(): void
    {
        $definitions = [
            'sylius_admin_product' => [
                'driver' => ['name' => 'doctrine/orm', 'options' => ['class' => 'App\\Entity\\Product']],
                'fields' => ['name' => [], 'code' => []],
                'filters' => ['search' => []],
                'actions' => ['main' => [], 'item' => []],
            ],
            'app_back_in_stock_notification' => [
                'driver' => ['name' => 'doctrine/orm', 'options' => ['class' => 'App\\Entity\\BackInStockNotification']],
                'fields' => ['email' => []],
            ],
        ];

        $tool = new ListGrids($this->host($definitions));

        $result = ($tool)();

        self::assertCount(2, $result['items']);
        $product = $this->itemByName($result['items'], 'sylius_admin_product');
        self::assertSame('doctrine/orm', $product['driver']);
        self::assertSame('App\\Entity\\Product', $product['resource_class']);
        self::assertSame(['name', 'code'], $product['fields']);
        self::assertSame(['search'], $product['filters']);
        self::assertSame(['main', 'item'], $product['actions']);
    }

    public function testFiltersByNamePrefix(): void
    {
        $tool = new ListGrids($this->host([
            'sylius_admin_product' => ['driver' => []],
            'app_thing' => ['driver' => []],
        ]));

        $result = ($tool)('sylius_admin_');

        self::assertCount(1, $result['items']);
        self::assertSame('sylius_admin_product', $result['items'][0]['name']);
    }

    public function testReturnsEmptyWhenParameterMissing(): void
    {
        $tool = new ListGrids(new FakeHostContainerProvider(new Container()));

        $result = ($tool)();

        self::assertSame([], $result['items']);
        self::assertStringContainsString('SyliusGridBundle', $result['note']);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function host(array $definitions): FakeHostContainerProvider
    {
        $container = new Container(new ParameterBag(['sylius.grids_definitions' => $definitions]));

        return new FakeHostContainerProvider($container);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function itemByName(array $items, string $name): array
    {
        foreach ($items as $item) {
            if ($item['name'] === $name) {
                return $item;
            }
        }

        self::fail(sprintf('No grid named "%s" in items.', $name));
    }
}
