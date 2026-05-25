<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Twig;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Twig\ListFunctions;
use Symfony\Component\DependencyInjection\Container;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

final class ListFunctionsTest extends TestCase
{
    public function testReturnsFunctionsFiltersAndTestsWithOrigins(): void
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addFunction(new TwigFunction('sylius_inventory_is_available', [self::class, 'sampleHelper']));
        $twig->addFilter(new TwigFilter('sylius_format_price', [self::class, 'sampleHelper']));
        $twig->addTest(new TwigTest('available', [self::class, 'sampleHelper']));

        $tool = new ListFunctions($this->host($twig));

        $result = ($tool)('sylius_');

        $names = array_map(static fn (array $row) => $row['name'], $result['items']);
        self::assertContains('sylius_inventory_is_available', $names);
        self::assertContains('sylius_format_price', $names);

        $function = $result['items'][array_search('sylius_inventory_is_available', $names, true)];
        self::assertSame('function', $function['kind']);
        self::assertSame(self::class . '::sampleHelper', $function['origin']);
    }

    public function testRejectsUnknownKind(): void
    {
        $tool = new ListFunctions($this->host(new Environment(new ArrayLoader())));

        $result = ($tool)(kind: 'bogus');

        self::assertSame('invalid_kind', $result['error']['code']);
    }

    public function testFiltersByKind(): void
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addFunction(new TwigFunction('only_fn', [self::class, 'sampleHelper']));
        $twig->addFilter(new TwigFilter('only_filter', [self::class, 'sampleHelper']));

        $tool = new ListFunctions($this->host($twig));

        $result = ($tool)(kind: 'filter');

        $kinds = array_values(array_unique(array_column($result['items'], 'kind')));
        self::assertSame(['filter'], $kinds);
    }

    public static function sampleHelper(): void
    {
    }

    private function host(Environment $twig): FakeHostContainerProvider
    {
        $container = new Container();
        $container->set('twig', $twig);

        return new FakeHostContainerProvider($container);
    }
}
