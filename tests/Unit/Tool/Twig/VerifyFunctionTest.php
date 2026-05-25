<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Twig;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Twig\VerifyFunction;
use Symfony\Component\DependencyInjection\Container;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class VerifyFunctionTest extends TestCase
{
    public function testReturnsSignatureForKnownFunction(): void
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addFunction(new TwigFunction('sylius_inventory_is_available', [self::class, 'helperFn']));

        $tool = new VerifyFunction($this->host($twig));

        $result = ($tool)('sylius_inventory_is_available');

        self::assertCount(1, $result['items']);
        self::assertSame('function', $result['items'][0]['kind']);
        self::assertSame(self::class . '::helperFn', $result['items'][0]['origin']);
        $params = $result['items'][0]['parameters'];
        self::assertSame('variant', $params[0]['name']);
        self::assertSame('string', $params[0]['type']);
        self::assertFalse($params[0]['optional']);
        self::assertTrue($params[1]['optional']);
    }

    public function testReturnsEmptyEnvelopeForUnknownName(): void
    {
        $tool = new VerifyFunction($this->host(new Environment(new ArrayLoader())));

        $result = ($tool)('sylius_bogus_thing');

        self::assertSame([], $result['items']);
        self::assertStringContainsString('is registered', $result['note']);
        self::assertStringContainsString('Do not use it', $result['note']);
    }

    public function testRejectsInvalidKind(): void
    {
        $tool = new VerifyFunction($this->host(new Environment(new ArrayLoader())));

        $result = ($tool)('foo', 'gizmo');

        self::assertSame('invalid_kind', $result['error']['code']);
    }

    public static function helperFn(string $variant, bool $strict = false): bool
    {
        return $strict && '' !== $variant;
    }

    private function host(Environment $twig): FakeHostContainerProvider
    {
        $container = new Container();
        $container->set('twig', $twig);

        return new FakeHostContainerProvider($container);
    }
}
