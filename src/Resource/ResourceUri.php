<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Resource;

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
