<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Fake;

use Sylius\MateExtension\Kernel\PackagistClient;

final class FakePackagistClient implements PackagistClient
{
    /**
     * @param array<string, ?list<array<string, mixed>>> $responses
     */
    public function __construct(
        private readonly array $responses = [],
    ) {
    }

    public function fetchPackageVersions(string $packageName): ?array
    {
        if (!\array_key_exists($packageName, $this->responses)) {
            return null;
        }

        return $this->responses[$packageName];
    }
}
