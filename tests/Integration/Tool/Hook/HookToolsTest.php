<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Hook;

use Sylius\MateExtension\Hook\HookablesReader;
use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tool\Hook\FindHookForTemplate;
use Sylius\MateExtension\Tool\Hook\ListHookables;
use Sylius\MateExtension\Tool\Hook\ListHooks;
use Sylius\MateExtension\Tool\Hook\ResolveForVisibility;

final class HookToolsTest extends IntegrationTestCase
{
    public function testListsProjectOwnedHookWithItsHookableCount(): void
    {
        $result = (new ListHooks($this->reader()))('mate_test.');

        self::assertSame([['name' => 'mate_test.page.content', 'hookable_count' => 2]], $result['items']);
    }

    public function testListsHookablesSortedByPriorityWithTemplateMetadata(): void
    {
        $result = (new ListHookables($this->reader()))('mate_test.page.content');

        self::assertSame(['greeting', 'footer'], array_column($result['items'], 'name'));
        self::assertSame([5, -5], array_column($result['items'], 'priority'));
        self::assertSame('template', $result['items'][0]['kind']);
        self::assertSame('mate_test/greeting.html.twig', $result['items'][0]['template']);
        self::assertSame('mate_test.page.content#greeting', $result['items'][0]['id']);
    }

    public function testFindsTheHookRenderingATemplate(): void
    {
        $result = (new FindHookForTemplate($this->reader()))('mate_test/footer.html.twig');

        self::assertCount(1, $result['items']);
        self::assertSame('mate_test.page.content', $result['items'][0]['hook_name']);
        self::assertSame('footer', $result['items'][0]['hookable_name']);
    }

    public function testSeesStockSyliusHooksAndComponentHookables(): void
    {
        $hooks = (new ListHooks($this->reader()))('sylius_admin.', 2000);
        self::assertGreaterThan(100, \count($hooks['items']));

        $hookables = (new ListHookables($this->reader()))('sylius_admin.admin_user.create.content');
        $kinds = array_unique(array_column($hookables['items'], 'kind'));
        self::assertContains('component', $kinds);
    }

    public function testResolvesVisibilityAgainstRealHooks(): void
    {
        $result = (new ResolveForVisibility($this->reader(), self::host()))('always', 'product.show', 'shop');

        self::assertNotEmpty($result['items']);
        self::assertSame('always', $result['visibility']);
        foreach ($result['items'] as $item) {
            self::assertStringStartsWith('sylius_shop.', $item['name']);
            self::assertStringContainsString('product.show', $item['name']);
        }
    }

    private function reader(): HookablesReader
    {
        return new HookablesReader(self::host());
    }
}
