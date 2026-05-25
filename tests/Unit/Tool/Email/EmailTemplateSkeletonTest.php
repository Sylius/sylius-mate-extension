<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Email;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tool\Email\EmailTemplateSkeleton;

final class EmailTemplateSkeletonTest extends TestCase
{
    private EmailTemplateSkeleton $tool;

    protected function setUp(): void
    {
        $this->tool = new EmailTemplateSkeleton(\dirname(__DIR__, 4) . '/src/Scaffold');
    }

    public function testEmitsTwigAndMailerYaml(): void
    {
        $result = ($this->tool)('back_in_stock', ['variant', 'localeCode']);

        $kinds = array_column($result['items'], 'kind');
        self::assertSame(['email_template', 'mailer_config', 'translation_keys'], $kinds);

        $twig = $result['items'][0]['body'];
        self::assertStringContainsString("@SyliusCore/Email/layout.html.twig", $twig);
        self::assertStringContainsString("app.email.back_in_stock.subject", $twig);
        self::assertStringContainsString('localeCode|default', $twig);

        $yaml = $result['items'][1]['body'];
        self::assertStringContainsString('back_in_stock:', $yaml);
        self::assertStringContainsString("template: 'email/back_in_stock.html.twig'", $yaml);
    }

    public function testRejectsInvalidCode(): void
    {
        $result = ($this->tool)('BAD-CODE');

        self::assertSame('invalid_code', $result['error']['code']);
    }
}
