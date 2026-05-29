<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Project;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Output\Envelope;

#[McpTool(
    name: 'sylius_installed_plugins',
    description: 'List active Sylius plugins (from config/bundles.php + composer.lock) with decorator map (core services they replace) and key entities. Drives listener-target decisions: e.g. with sylius/multi-source-inventory-plugin installed, stock data lives on InventorySourceStockInterface, NOT ProductVariant.onHand. Call before designing any inventory/order/price/availability listener.',
)]
final class InstalledPlugins
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const KNOWN_PLUGINS = [
        'Sylius\\MultiSourceInventoryPlugin\\SyliusMultiSourceInventoryPlugin' => [
            'name' => 'sylius/multi-source-inventory-plugin',
            'decorates' => [
                [
                    'core_service' => 'sylius.checker.inventory.availability',
                    'decorator_class' => 'Sylius\\MultiSourceInventoryPlugin\\Inventory\\Checker\\AvailabilityChecker',
                    'implication' => 'Stock data lives in InventorySourceStockInterface rows, NOT ProductVariant.onHand.',
                ],
            ],
            'key_entities' => [
                'Sylius\\MultiSourceInventoryPlugin\\Domain\\Model\\InventorySourceStockInterface',
                'Sylius\\MultiSourceInventoryPlugin\\Domain\\Model\\InventorySourceInterface',
            ],
        ],
        'BitBag\\SyliusWishlistPlugin\\BitBagSyliusWishlistPlugin' => [
            'name' => 'bitbag/wishlist-plugin',
            'decorates' => [],
            'key_entities' => [
                'BitBag\\SyliusWishlistPlugin\\Entity\\WishlistInterface',
            ],
        ],
        'Sylius\\RefundPlugin\\SyliusRefundPlugin' => [
            'name' => 'sylius/refund-plugin',
            'decorates' => [],
            'key_entities' => [
                'Sylius\\RefundPlugin\\Entity\\RefundInterface',
            ],
        ],
        'Sylius\\InvoicingPlugin\\SyliusInvoicingPlugin' => [
            'name' => 'sylius/invoicing-plugin',
            'decorates' => [],
            'key_entities' => [
                'Sylius\\InvoicingPlugin\\Entity\\InvoiceInterface',
            ],
        ],
    ];

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return Envelope::guard(fn (): array => $this->detect());
    }

    /**
     * @return array<string, mixed>
     */
    private function detect(): array
    {
        $projectDir = HostProjectDir::resolve($this->host);
        $bundlesFile = $projectDir . '/config/bundles.php';

        if (!is_file($bundlesFile)) {
            return Envelope::empty(sprintf('No bundles.php at "%s".', $bundlesFile));
        }

        /** @var array<string, array<string, bool>>|mixed $bundles */
        $bundles = require $bundlesFile;
        if (!\is_array($bundles)) {
            return Envelope::error('invalid_bundles', 'config/bundles.php did not return an array.');
        }

        $lock = $this->readComposerLock($projectDir);

        $plugins = [];
        foreach ($bundles as $bundleClass => $envs) {
            if (!\is_string($bundleClass)) {
                continue;
            }

            if (!isset(self::KNOWN_PLUGINS[$bundleClass])) {
                continue;
            }

            $plugin = self::KNOWN_PLUGINS[$bundleClass];
            $packageName = (string) $plugin['name'];
            $plugin['bundle_class'] = $bundleClass;
            $plugin['version'] = $lock[$packageName] ?? null;
            $plugins[] = $plugin;
        }

        return Envelope::items($plugins, null, sprintf(
            'Detected %d known Sylius plugin(s). Use decorates[].implication to pick listener targets.',
            \count($plugins),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function readComposerLock(string $projectDir): array
    {
        $lockFile = $projectDir . '/composer.lock';
        if (!is_file($lockFile)) {
            return [];
        }

        $raw = @file_get_contents($lockFile);
        if (false === $raw) {
            return [];
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $map = [];
        foreach (['packages', 'packages-dev'] as $key) {
            $packages = $data[$key] ?? [];
            if (!\is_array($packages)) {
                continue;
            }

            foreach ($packages as $package) {
                if (\is_array($package) && isset($package['name'], $package['version']) && \is_string($package['name']) && \is_string($package['version'])) {
                    $map[$package['name']] = $package['version'];
                }
            }
        }

        return $map;
    }
}
