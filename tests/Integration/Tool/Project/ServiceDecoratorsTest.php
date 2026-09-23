<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Project;

use Sylius\Component\Core\Calculator\ProductVariantPriceCalculator;
use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tests\TestApplication\Calculator\InnerProductVariantPricesCalculator;
use Sylius\MateExtension\Tests\TestApplication\Calculator\OuterProductVariantPricesCalculator;
use Sylius\MateExtension\Tool\Project\ServiceDecorators;

final class ServiceDecoratorsTest extends IntegrationTestCase
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $items = null;

    public function testReconstructsProjectOwnedDecorationChainFromTheCompiledContainer(): void
    {
        $chain = $this->chainFor('sylius.calculator.product_variant_price');

        self::assertCount(2, $chain);

        self::assertSame(1, $chain[0]['chain_position']);
        self::assertSame(2, $chain[0]['chain_length']);
        self::assertSame(OuterProductVariantPricesCalculator::class, $chain[0]['decorator_class']);
        self::assertSame(OuterProductVariantPricesCalculator::class, $chain[0]['decorator_service_id']);
        self::assertNull($chain[0]['decorator_package']);

        self::assertSame(2, $chain[1]['chain_position']);
        self::assertSame(InnerProductVariantPricesCalculator::class, $chain[1]['decorator_class']);
        self::assertNull($chain[1]['decorator_package']);

        self::assertSame(ProductVariantPriceCalculator::class, $chain[0]['original_class']);
        self::assertSame(ProductVariantPriceCalculator::class, $chain[1]['original_class']);
    }

    public function testSeesDecoratorsRegisteredBySyliusCoreItselfWithTheirPackage(): void
    {
        $fromCore = array_filter(
            self::items(),
            static fn (array $item): bool => 'sylius/sylius' === $item['decorator_package'],
        );

        self::assertNotEmpty($fromCore);
        foreach ($fromCore as $item) {
            self::assertNotNull($item['decorator_package_version']);
            self::assertMatchesRegularExpression('/^sylius[._]/', $item['original_service_id']);
        }
    }

    /**
     * The dump is parsed once per class — every test reads the same items.
     *
     * @return list<array<string, mixed>>
     */
    private static function items(): array
    {
        return self::$items ??= (new ServiceDecorators(self::host()))()['items'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chainFor(string $originalId): array
    {
        return array_values(array_filter(
            self::items(),
            static fn (array $item): bool => $originalId === $item['original_service_id'],
        ));
    }
}
