<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Playwright;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tool\Playwright\PlaywrightRecipe;

final class PlaywrightRecipeTest extends TestCase
{
    private PlaywrightRecipe $tool;

    protected function setUp(): void
    {
        $this->tool = new PlaywrightRecipe(\dirname(__DIR__, 4) . '/src/Scaffold');
    }

    public function testRendersSpec(): void
    {
        $result = ($this->tool)(
            feature_alias: 'back_in_stock_notify',
            title: 'subscribe to a variant and assert email',
            steps: [
                'setup' => ['await page.goto("/")'],
                'scenario' => ['await expect(page.getByTestId("x")).toBeVisible()'],
                'teardown' => ['await page.close()'],
            ],
        );

        self::assertSame('playwright_spec', $result['items'][0]['kind']);
        self::assertSame('tests/Playwright/back_in_stock_notify.spec.ts', $result['items'][0]['suggested_path']);

        $body = $result['items'][0]['body'];
        self::assertStringContainsString("test('subscribe to a variant and assert email'", $body);
        self::assertStringContainsString('// SETUP', $body);
        self::assertStringContainsString('await page.goto("/");', $body);
        self::assertStringContainsString('// TEARDOWN', $body);
    }

    public function testRejectsInvalidAlias(): void
    {
        $result = ($this->tool)('BAD-ALIAS', 'T', ['scenario' => ['x']]);

        self::assertSame('invalid_feature_alias', $result['error']['code']);
    }
}
