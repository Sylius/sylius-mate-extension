<?php

declare(strict_types=1);

namespace {{ namespace }}\EventListener;

use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Listener template — uses resource CODES (string), never integer IDs.
 *
 * RULE R-LISTENER-CODE-NOT-ID: listeners persist work keyed by resource code,
 * never by primary-key id. Ids may not be assigned yet at pre_create / pre_update
 * time and rotate across environments (fixtures, tests).
 */
final class {{ model }}Listener
{
    public function onProductVariantPostUpdate(ProductVariantInterface $variant): void
    {
        $variantCode = $variant->getCode();
        if (null === $variantCode) {
            return;
        }

        // TODO: load related entities by code, e.g.
        // $this->notificationRepository->findPendingByVariantCode($variantCode)
    }
}
