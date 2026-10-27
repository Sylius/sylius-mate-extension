<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Resource;

/**
 * Every `sylius://…` resource URI is built here and its placeholders decoded
 * here. Identifiers come from the host (Twig names such as "divisible by",
 * arbitrary route and grid names), so each segment is percent-encoded to keep
 * the URI valid and matching the template's `[^/]+` placeholder; Mate hands
 * the matched segment back verbatim, so read() methods decode it.
 */
final class ResourceUri
{
    public static function build(string $prefix, string ...$segments): string
    {
        return $prefix . implode('/', array_map(rawurlencode(...), $segments));
    }

    public static function decode(string $segment): string
    {
        return rawurldecode($segment);
    }
}
