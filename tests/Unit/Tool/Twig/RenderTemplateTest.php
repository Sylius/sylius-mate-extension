<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Twig;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Twig\RenderTemplate;
use Symfony\Component\DependencyInjection\Container;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class RenderTemplateTest extends TestCase
{
    public function testRendersTemplateWithContext(): void
    {
        $tool = new RenderTemplate($this->host([
            'greet.twig' => 'Hello {{ name }}!',
        ]));

        $result = ($tool)('greet.twig', ['name' => 'Sylius']);

        self::assertSame('Hello Sylius!', $result['items'][0]['output']);
        self::assertSame(13, $result['items'][0]['size_bytes']);
    }

    public function testReturnsStructuredErrorOnSyntaxFailure(): void
    {
        $tool = new RenderTemplate($this->host([
            'broken.twig' => '{{ missing_function() }}',
        ]));

        $result = ($tool)('broken.twig');

        self::assertSame('render_failed', $result['error']['code']);
        self::assertStringContainsString('missing_function', $result['error']['message']);
    }

    public function testFlagsUnknownTemplate(): void
    {
        $tool = new RenderTemplate($this->host([]));

        $result = ($tool)('nope.twig');

        self::assertSame('template_not_found', $result['error']['code']);
    }

    /**
     * @param array<string, string> $templates
     */
    private function host(array $templates): FakeHostContainerProvider
    {
        $container = new Container();
        $container->set('twig', new Environment(new ArrayLoader($templates)));

        return new FakeHostContainerProvider($container);
    }
}
