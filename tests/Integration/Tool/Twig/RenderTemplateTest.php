<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Twig;

use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tool\Twig\RenderTemplate;

final class RenderTemplateTest extends IntegrationTestCase
{
    public function testRendersFixtureTemplateWithContext(): void
    {
        $result = (new RenderTemplate(self::host()))('mate_test/greeting.html.twig', ['name' => 'Sylius']);

        self::assertSame('Hello, Sylius!', trim($result['items'][0]['output']));
    }

    public function testReportsUndefinedFunctionAsRenderFailure(): void
    {
        $result = (new RenderTemplate(self::host()))('mate_test/broken.html.twig', ['name' => 'Sylius']);

        self::assertSame('render_failed', $result['error']['code']);
        self::assertStringContainsString('mate_test_undefined', $result['error']['message']);
        self::assertStringContainsString('broken.html.twig:1', $result['error']['hint']);
    }

    public function testReportsMissingTemplate(): void
    {
        $result = (new RenderTemplate(self::host()))('mate_test/missing.html.twig');

        self::assertSame('template_not_found', $result['error']['code']);
    }
}
