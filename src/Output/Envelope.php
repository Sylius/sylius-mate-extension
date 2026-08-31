<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Output;

final class Envelope
{
    public const SCHEMA_VERSION = '1.0.0';

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array{schema_version: string, items: list<array<string, mixed>>, cursor?: ?string, note?: string}
     */
    public static function items(array $items, ?string $cursor = null, ?string $note = null): array
    {
        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'items' => array_values($items),
        ];

        if (null !== $cursor) {
            $envelope['cursor'] = $cursor;
        }

        if (null !== $note) {
            $envelope['note'] = $note;
        }

        return $envelope;
    }

    /**
     * @return array{schema_version: string, items: list<never>, note: string}
     */
    public static function empty(string $note): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'items' => [],
            'note' => $note,
        ];
    }

    /**
     * @return array{schema_version: string, error: array{code: string, message: string, hint?: string}}
     */
    public static function error(string $code, string $message, ?string $hint = null): array
    {
        $error = ['code' => $code, 'message' => $message];

        if (null !== $hint) {
            $error['hint'] = $hint;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'error' => $error,
        ];
    }

    /**
     * Catch any exception inside the closure and return a structured error
     * envelope instead of letting the CLI surface a raw exception trace.
     *
     * @param callable(): array<string, mixed> $invocation
     *
     * @return array<string, mixed>
     */
    public static function guard(callable $invocation, string $errorCode = 'tool_failed'): array
    {
        try {
            return $invocation();
        } catch (\Throwable $e) {
            return self::error(
                $errorCode,
                sprintf('%s: %s', $e::class, $e->getMessage()),
                'Likely cause: host kernel container cache is stale or a host service is missing. Run sylius_cache_clear and retry.',
            );
        }
    }

    /**
     * @template T
     *
     * @param list<T> $all
     *
     * @return array{slice: list<T>, cursor: ?string}
     */
    public static function paginate(array $all, int $limit, ?string $cursor): array
    {
        $offset = null === $cursor ? 0 : max(0, (int) $cursor);
        $slice = \array_slice($all, $offset, $limit);
        $next = ($offset + $limit) < \count($all) ? (string) ($offset + $limit) : null;

        return ['slice' => $slice, 'cursor' => $next];
    }
}
