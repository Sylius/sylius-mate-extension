<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Kernel;

interface PackagistClient
{
    /**
     * Fetches every published release of a package with its `require` map.
     * Returns null on any failure (network, non-200, malformed JSON) — a
     * best-effort lookup, callers must treat that as "unknown", not "no
     * releases".
     *
     * @return ?list<array<string, mixed>>
     */
    public function fetchPackageVersions(string $packageName): ?array;
}
