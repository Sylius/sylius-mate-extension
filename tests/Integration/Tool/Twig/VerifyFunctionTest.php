<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Twig;

use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tool\Twig\VerifyFunction;

final class VerifyFunctionTest extends IntegrationTestCase
{
    public function testReflectsSignatureOfFixtureFunction(): void
    {
        $result = (new VerifyFunction(self::host()))('mate_test_greet');

        self::assertCount(1, $result['items']);
        $match = $result['items'][0];
        self::assertSame('function', $match['kind']);
        self::assertSame(
            [
                ['name' => 'name', 'type' => 'string', 'optional' => false, 'variadic' => false],
                ['name' => 'greeting', 'type' => 'string', 'optional' => true, 'variadic' => false],
            ],
            $match['parameters'],
        );
    }

    public function testDistinguishesFilterFromFunction(): void
    {
        $tool = new VerifyFunction(self::host());

        self::assertSame('filter', $tool('mate_test_shout')['items'][0]['kind']);
        self::assertSame([], $tool('mate_test_shout', 'function')['items']);
    }

    public function testUnknownCallableIsReportedAsEmpty(): void
    {
        $result = (new VerifyFunction(self::host()))('mate_test_undefined');

        self::assertSame([], $result['items']);
        self::assertStringContainsString('Do not use it in templates', $result['note']);
    }
}
