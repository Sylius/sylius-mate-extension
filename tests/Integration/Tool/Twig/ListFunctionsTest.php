<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Twig;

use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tests\TestApplication\Twig\MateTestExtension;
use Sylius\MateExtension\Tool\Twig\ListFunctions;

final class ListFunctionsTest extends IntegrationTestCase
{
    public function testListsFixtureCallablesWithOrigin(): void
    {
        $result = (new ListFunctions(self::host()))('mate_test_');

        self::assertSame(
            [['filter', 'mate_test_shout'], ['function', 'mate_test_greet']],
            array_map(static fn (array $item): array => [$item['kind'], $item['name']], $result['items']),
        );
        foreach ($result['items'] as $item) {
            self::assertSame(MateTestExtension::class, $item['origin']);
        }
    }

    public function testNarrowsByKind(): void
    {
        $result = (new ListFunctions(self::host()))('mate_test_', 'filter');

        self::assertSame(['mate_test_shout'], array_column($result['items'], 'name'));
    }

    public function testSeesStockSyliusHelpers(): void
    {
        $result = (new ListFunctions(self::host()))('sylius_', 'filter', 500);

        self::assertContains('sylius_format_money', array_column($result['items'], 'name'));
    }
}
