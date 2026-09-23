<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Resource;

use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tests\TestApplication\Entity\Book;
use Sylius\MateExtension\Tool\Resource\ListResources;
use Sylius\Resource\Factory\Factory;

final class ListResourcesTest extends IntegrationTestCase
{
    public function testListsProjectOwnedResourceWithSyliusDefaultsFilledIn(): void
    {
        $result = (new ListResources(self::host()))('mate_test.');

        self::assertCount(1, $result['items']);
        $book = $result['items'][0];
        self::assertSame('mate_test.book', $book['alias']);
        self::assertSame('mate_test', $book['application']);
        self::assertSame('book', $book['name']);
        self::assertSame('books', $book['plural_name']);
        self::assertSame('doctrine/orm', $book['driver']);
        self::assertSame(Book::class, $book['classes']['model']);
        self::assertSame(Factory::class, $book['classes']['factory']);
        self::assertArrayNotHasKey('repository', $book['classes']);
    }

    public function testSeesStockSyliusResources(): void
    {
        $result = (new ListResources(self::host()))('sylius.', 500);

        $aliases = array_column($result['items'], 'alias');
        self::assertContains('sylius.product', $aliases);
        self::assertContains('sylius.order', $aliases);
        self::assertGreaterThan(50, \count($aliases));
    }
}
