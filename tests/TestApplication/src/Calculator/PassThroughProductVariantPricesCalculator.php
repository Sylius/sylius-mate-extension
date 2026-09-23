<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\TestApplication\Calculator;

use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Base of the project-owned decorators of sylius.calculator.product_variant_price.
 * Behaviour is irrelevant — sylius_service_decorators only needs two distinct
 * classes wired as a decoration chain.
 */
abstract class PassThroughProductVariantPricesCalculator implements ProductVariantPricesCalculatorInterface
{
    public function __construct(
        private readonly ProductVariantPricesCalculatorInterface $inner,
    ) {
    }

    public function calculate(ProductVariantInterface $productVariant, array $context): int
    {
        return $this->inner->calculate($productVariant, $context);
    }

    public function calculateOriginal(ProductVariantInterface $productVariant, array $context): int
    {
        return $this->inner->calculateOriginal($productVariant, $context);
    }

    public function calculateLowestPriceBeforeDiscount(ProductVariantInterface $productVariant, array $context): ?int
    {
        return $this->inner->calculateLowestPriceBeforeDiscount($productVariant, $context);
    }
}
