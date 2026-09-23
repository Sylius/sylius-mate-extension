<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Grid;

use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tool\Grid\ActionsAudit;

final class ActionsAuditTest extends IntegrationTestCase
{
    public function testFlagsItemScopedDeleteUnderMainActions(): void
    {
        $result = (new ActionsAudit(self::host()))('mate_test_book');

        $audit = $result['items'][0];
        self::assertSame(['create', 'delete'], $audit['main']);
        self::assertSame(['update'], $audit['item']);
        self::assertCount(1, $audit['warnings']);
        self::assertStringContainsString('"main.delete"', $audit['warnings'][0]);
    }

    public function testStockSyliusAdminProductGridIsClean(): void
    {
        $result = (new ActionsAudit(self::host()))('sylius_admin_product');

        self::assertSame([], $result['items'][0]['warnings']);
    }

    public function testUnknownGridIsReportedAsEmpty(): void
    {
        $result = (new ActionsAudit(self::host()))('mate_test_missing');

        self::assertSame([], $result['items']);
        self::assertStringContainsString('not registered', $result['note']);
    }
}
