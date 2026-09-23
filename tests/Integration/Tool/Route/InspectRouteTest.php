<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Route;

use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tool\Route\InspectRoute;

final class InspectRouteTest extends IntegrationTestCase
{
    public function testFlagsDuplicatedPathSegment(): void
    {
        $result = (new InspectRoute(self::host()))('mate_test_double_prefix');

        $route = $result['items'][0];
        self::assertSame('/mate-test/books/books/{id}', $route['path']);
        self::assertSame(['books'], $route['duplicate_segments']);
        self::assertCount(1, $route['warnings']);
        self::assertStringContainsString('"books"', $route['warnings'][0]);
        self::assertSame(['id' => '\d+'], $route['requirements']);
    }

    public function testCleanRouteHasNoWarnings(): void
    {
        $result = (new InspectRoute(self::host()))('mate_test_ping');

        $route = $result['items'][0];
        self::assertSame([], $route['duplicate_segments']);
        self::assertSame([], $route['warnings']);
        self::assertStringContainsString('looks clean', $result['note']);
    }
}
