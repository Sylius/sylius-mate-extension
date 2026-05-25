<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Mailer;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Mailer\VerifyTemplate;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class VerifyTemplateTest extends TestCase
{
    public function testPassesWhenTemplateResolvesAndHasContent(): void
    {
        $tool = new VerifyTemplate($this->host(
            ['back_in_stock' => ['template' => 'email/back_in_stock.html.twig']],
            ['email/back_in_stock.html.twig' => '<p>hello</p>'],
        ));

        $result = ($tool)('back_in_stock');

        $checks = $result['items'];
        self::assertTrue($checks[0]['ok']);
        self::assertTrue($checks[1]['ok']);
        self::assertSame('email/back_in_stock.html.twig', $result['template']['logical_name']);
        self::assertGreaterThan(0, $result['template']['size_bytes']);
    }

    public function testFlagsMissingTemplate(): void
    {
        $tool = new VerifyTemplate($this->host(
            ['back_in_stock' => ['template' => 'email/back_in_stock.html.twig']],
            [],
        ));

        $result = ($tool)('back_in_stock');

        self::assertFalse($result['items'][0]['ok']);
    }

    public function testFlagsEmptyTemplate(): void
    {
        $tool = new VerifyTemplate($this->host(
            ['back_in_stock' => ['template' => 'email/back_in_stock.html.twig']],
            ['email/back_in_stock.html.twig' => ''],
        ));

        $result = ($tool)('back_in_stock');

        self::assertTrue($result['items'][0]['ok']);
        self::assertFalse($result['items'][1]['ok']);
        self::assertSame(0, $result['template']['size_bytes']);
    }

    public function testRejectsUnknownEmailCode(): void
    {
        $tool = new VerifyTemplate($this->host([], []));

        $result = ($tool)('nope');

        self::assertSame('unknown_email_code', $result['error']['code']);
    }

    /**
     * @param array<string, array<string, mixed>> $emails
     * @param array<string, string>               $templates
     */
    private function host(array $emails, array $templates): FakeHostContainerProvider
    {
        $container = new Container(new ParameterBag(['sylius.mailer.emails' => $emails]));
        $container->set('twig', new Environment(new ArrayLoader($templates)));

        return new FakeHostContainerProvider($container);
    }
}
