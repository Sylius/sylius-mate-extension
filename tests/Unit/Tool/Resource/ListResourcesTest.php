<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Resource;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Resource\ListResources;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class ListResourcesTest extends TestCase
{
    public function testReturnsAllRegisteredResources(): void
    {
        $tool = new ListResources($this->host([
            'app.foo' => [
                'driver' => 'doctrine/orm',
                'classes' => [
                    'model' => 'App\\Entity\\Foo',
                    'interface' => 'App\\Entity\\FooInterface',
                    'repository' => 'App\\Repository\\FooRepository',
                    'factory' => 'App\\Factory\\FooFactory',
                    'form' => 'App\\Form\\Type\\FooType',
                ],
            ],
            'app.bar' => [
                'driver' => 'doctrine/orm',
                'classes' => [
                    'model' => 'App\\Entity\\Bar',
                ],
            ],
        ]));

        $result = ($tool)();

        self::assertCount(2, $result['items']);
        self::assertSame('app.bar', $result['items'][0]['alias']);
        self::assertSame('app.foo', $result['items'][1]['alias']);
        self::assertSame('App\\Entity\\Foo', $result['items'][1]['classes']['model']);
        self::assertSame('App\\Repository\\FooRepository', $result['items'][1]['classes']['repository']);
    }

    public function testFiltersByAliasPrefix(): void
    {
        $tool = new ListResources($this->host([
            'app.foo' => ['driver' => 'doctrine/orm', 'classes' => ['model' => 'Foo']],
            'sylius.product' => ['driver' => 'doctrine/orm', 'classes' => ['model' => 'Product']],
        ]));

        $result = ($tool)('sylius.');

        self::assertCount(1, $result['items']);
        self::assertSame('sylius.product', $result['items'][0]['alias']);
    }

    public function testReturnsEmptyEnvelopeWhenNothingMatches(): void
    {
        $tool = new ListResources($this->host([]));

        $result = ($tool)('missing.');

        self::assertSame([], $result['items']);
        self::assertStringContainsString('No resources matched', $result['note']);
    }

    public function testPaginatesWithCursor(): void
    {
        $resources = [];
        foreach (['a.1', 'a.2', 'a.3', 'a.4'] as $alias) {
            $resources[$alias] = ['driver' => 'doctrine/orm', 'classes' => ['model' => 'M']];
        }

        $tool = new ListResources($this->host($resources));

        $first = ($tool)(limit: 2);
        self::assertSame(['a.1', 'a.2'], array_column($first['items'], 'alias'));
        self::assertSame('2', $first['cursor']);

        $second = ($tool)(limit: 2, cursor: '2');
        self::assertSame(['a.3', 'a.4'], array_column($second['items'], 'alias'));
        self::assertArrayNotHasKey('cursor', $second);
    }

    public function testErrorsWhenResourceBundleIsNotEnabled(): void
    {
        $tool = new ListResources(new FakeHostContainerProvider(new Container()));

        $result = ($tool)();

        self::assertSame('registry_unavailable', $result['error']['code']);
    }

    /**
     * @param array<string, array<string, mixed>> $resources the `sylius.resources` parameter shape
     */
    private function host(array $resources): FakeHostContainerProvider
    {
        return new FakeHostContainerProvider(new Container(new ParameterBag(['sylius.resources' => $resources])));
    }
}
