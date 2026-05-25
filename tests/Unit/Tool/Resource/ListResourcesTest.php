<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Resource;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Resource\ListResources;
use Sylius\Resource\Metadata\Registry;
use Symfony\Component\DependencyInjection\Container;

final class ListResourcesTest extends TestCase
{
    public function testReturnsAllRegisteredResources(): void
    {
        $registry = new Registry();
        $registry->addFromAliasAndConfiguration('app.foo', [
            'driver' => 'doctrine/orm',
            'classes' => [
                'model' => 'App\\Entity\\Foo',
                'interface' => 'App\\Entity\\FooInterface',
                'repository' => 'App\\Repository\\FooRepository',
                'factory' => 'App\\Factory\\FooFactory',
                'form' => 'App\\Form\\Type\\FooType',
            ],
        ]);
        $registry->addFromAliasAndConfiguration('app.bar', [
            'driver' => 'doctrine/orm',
            'classes' => [
                'model' => 'App\\Entity\\Bar',
            ],
        ]);

        $tool = new ListResources($this->host($registry));

        $result = ($tool)();

        self::assertCount(2, $result['items']);
        self::assertSame('app.bar', $result['items'][0]['alias']);
        self::assertSame('app.foo', $result['items'][1]['alias']);
        self::assertSame('App\\Entity\\Foo', $result['items'][1]['classes']['model']);
        self::assertSame('App\\Repository\\FooRepository', $result['items'][1]['classes']['repository']);
    }

    public function testFiltersByAliasPrefix(): void
    {
        $registry = new Registry();
        $registry->addFromAliasAndConfiguration('app.foo', ['driver' => 'doctrine/orm', 'classes' => ['model' => 'Foo']]);
        $registry->addFromAliasAndConfiguration('sylius.product', ['driver' => 'doctrine/orm', 'classes' => ['model' => 'Product']]);

        $tool = new ListResources($this->host($registry));

        $result = ($tool)('sylius.');

        self::assertCount(1, $result['items']);
        self::assertSame('sylius.product', $result['items'][0]['alias']);
    }

    public function testReturnsEmptyEnvelopeWhenNothingMatches(): void
    {
        $tool = new ListResources($this->host(new Registry()));

        $result = ($tool)('missing.');

        self::assertSame([], $result['items']);
        self::assertStringContainsString('No resources matched', $result['note']);
    }

    public function testPaginatesWithCursor(): void
    {
        $registry = new Registry();
        foreach (['a.1', 'a.2', 'a.3', 'a.4'] as $alias) {
            $registry->addFromAliasAndConfiguration($alias, ['driver' => 'doctrine/orm', 'classes' => ['model' => 'M']]);
        }

        $tool = new ListResources($this->host($registry));

        $first = ($tool)(limit: 2);
        self::assertSame(['a.1', 'a.2'], array_column($first['items'], 'alias'));
        self::assertSame('2', $first['cursor']);

        $second = ($tool)(limit: 2, cursor: '2');
        self::assertSame(['a.3', 'a.4'], array_column($second['items'], 'alias'));
        self::assertArrayNotHasKey('cursor', $second);
    }

    private function host(Registry $registry): FakeHostContainerProvider
    {
        $container = new Container();
        $container->set('sylius.resource_registry', $registry);

        return new FakeHostContainerProvider($container);
    }
}
