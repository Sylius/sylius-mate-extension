<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Hook;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Hook\HookablesReader;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Hook\FindHookForTemplate;
use Sylius\MateExtension\Tool\Hook\ListHookables;
use Sylius\MateExtension\Tool\Hook\ListHooks;
use Sylius\TwigHooks\Hookable\HookableComponent;
use Sylius\TwigHooks\Hookable\HookableTemplate;
use Sylius\TwigHooks\Hookable\Merger\HookableMerger;
use Sylius\TwigHooks\Registry\HookablesRegistry;
use Symfony\Component\DependencyInjection\Container;

final class HookToolsTest extends TestCase
{
    public function testListHooksAggregatesHookableCounts(): void
    {
        $reader = $this->reader([
            new HookableTemplate('sylius_shop.product.show.content.info.summary', 'price', 'shop/product/show/price.html.twig'),
            new HookableTemplate('sylius_shop.product.show.content.info.summary', 'name', 'shop/product/show/name.html.twig'),
            new HookableComponent('sylius_admin.product.index.content.header', 'title', 'AdminTitle'),
        ]);

        $tool = new ListHooks($reader);

        $result = ($tool)();

        self::assertCount(2, $result['items']);
        $summary = $this->itemByName($result['items'], 'sylius_shop.product.show.content.info.summary');
        self::assertSame(2, $summary['hookable_count']);
    }

    public function testListHooksFiltersByPrefix(): void
    {
        $reader = $this->reader([
            new HookableTemplate('sylius_shop.foo', 'h', 'a.twig'),
            new HookableTemplate('sylius_admin.bar', 'h', 'b.twig'),
        ]);

        $tool = new ListHooks($reader);

        $result = ($tool)('sylius_admin.');

        self::assertCount(1, $result['items']);
        self::assertSame('sylius_admin.bar', $result['items'][0]['name']);
    }

    public function testListHookablesReturnsTemplateMetadataSortedByPriorityDesc(): void
    {
        $reader = $this->reader([
            new HookableTemplate('hook', 'low', 'low.twig', priority: 10),
            new HookableTemplate('hook', 'high', 'high.twig', priority: 100),
        ]);

        $tool = new ListHookables($reader);

        $result = ($tool)('hook');

        self::assertSame(['high', 'low'], array_column($result['items'], 'name'));
        self::assertSame('template', $result['items'][0]['kind']);
        self::assertSame('high.twig', $result['items'][0]['template']);
    }

    public function testListHookablesRejectsEmptyName(): void
    {
        $tool = new ListHookables($this->reader([]));

        $result = ($tool)('  ');

        self::assertSame('invalid_hook_name', $result['error']['code']);
    }

    public function testFindHookForTemplateMatchesByPath(): void
    {
        $reader = $this->reader([
            new HookableTemplate('shop.summary', 'price', 'shop/product/show/price.html.twig'),
            new HookableTemplate('admin.dashboard', 'widget', 'admin/dashboard/widget.html.twig'),
        ]);

        $tool = new FindHookForTemplate($reader);

        $result = ($tool)('shop/product/show/price.html.twig');

        self::assertCount(1, $result['items']);
        self::assertSame('shop.summary', $result['items'][0]['hook_name']);
        self::assertSame('price', $result['items'][0]['hookable_name']);
    }

    public function testFindHookForTemplateReturnsEmptyEnvelopeWhenNoMatch(): void
    {
        $tool = new FindHookForTemplate($this->reader([
            new HookableTemplate('hook', 'h', 'other.twig'),
        ]));

        $result = ($tool)('missing.twig');

        self::assertSame([], $result['items']);
        self::assertStringContainsString('No hook renders', $result['note']);
    }

    /**
     * @param list<\Sylius\TwigHooks\Hookable\AbstractHookable> $hookables
     */
    private function reader(array $hookables): HookablesReader
    {
        $registry = new HookablesRegistry($hookables, new HookableMerger());

        $container = new Container();
        $container->set('sylius_twig_hooks.registry.hookables', $registry);

        return new HookablesReader(new FakeHostContainerProvider($container));
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

        self::fail(sprintf('No hook named "%s" in items.', $name));
    }
}
