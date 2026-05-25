<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Output\Envelope;

final class EnvelopeTest extends TestCase
{
    public function testItemsEnvelopeShape(): void
    {
        $envelope = Envelope::items([['alias' => 'x']], '50');

        self::assertSame('1.0.0', $envelope['schema_version']);
        self::assertSame([['alias' => 'x']], $envelope['items']);
        self::assertSame('50', $envelope['cursor']);
    }

    public function testEmptyEnvelopeIncludesNote(): void
    {
        $envelope = Envelope::empty('try X');

        self::assertSame([], $envelope['items']);
        self::assertSame('try X', $envelope['note']);
    }

    public function testErrorEnvelope(): void
    {
        $envelope = Envelope::error('bad_arg', 'missing', 'call sylius_domain_list_resources');

        self::assertSame('bad_arg', $envelope['error']['code']);
        self::assertSame('missing', $envelope['error']['message']);
        self::assertSame('call sylius_domain_list_resources', $envelope['error']['hint']);
    }

    public function testPaginateSlicesAndExposesCursor(): void
    {
        $page = Envelope::paginate([1, 2, 3, 4, 5], 2, null);
        self::assertSame([1, 2], $page['slice']);
        self::assertSame('2', $page['cursor']);

        $next = Envelope::paginate([1, 2, 3, 4, 5], 2, '2');
        self::assertSame([3, 4], $next['slice']);
        self::assertSame('4', $next['cursor']);

        $last = Envelope::paginate([1, 2, 3, 4, 5], 2, '4');
        self::assertSame([5], $last['slice']);
        self::assertNull($last['cursor']);
    }
}
