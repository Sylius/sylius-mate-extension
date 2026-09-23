<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Resource;

use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tests\TestApplication\Entity\Book;
use Sylius\MateExtension\Tool\Resource\InspectResource;

final class InspectResourceTest extends IntegrationTestCase
{
    public function testInspectsProjectOwnedResource(): void
    {
        $result = (new InspectResource(self::host()))('mate_test.book');

        self::assertCount(1, $result['items']);
        $book = $result['items'][0];
        self::assertSame(Book::class, $book['classes']['model']);
        self::assertTrue($book['resource_interface_implemented']);
        self::assertSame('missing', $book['interface_mode']);
        self::assertTrue($book['factory']['constructor_signature_ok']);
        self::assertSame(
            [
                'factory' => 'mate_test.factory.book',
                'manager' => 'mate_test.manager.book',
                'repository' => 'mate_test.repository.book',
                'controller' => 'mate_test.controller.book',
                'form' => 'mate_test.form.book',
            ],
            $book['service_ids'],
        );
        self::assertCount(1, $book['warnings']);
        self::assertStringContainsString('No interface class configured', $book['warnings'][0]);
    }

    public function testInspectsStockSyliusResource(): void
    {
        $result = (new InspectResource(self::host()))('sylius.product');

        $product = $result['items'][0];
        self::assertSame('sylius.repository.product', $product['service_ids']['repository']);
        self::assertSame('explicit', $product['interface_mode']);
    }

    public function testUnknownAliasIsReportedAsEmpty(): void
    {
        $result = (new InspectResource(self::host()))('mate_test.missing');

        self::assertSame([], $result['items']);
        self::assertStringContainsString('not registered', $result['note']);
    }
}
