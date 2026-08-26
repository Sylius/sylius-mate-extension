<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Kernel;

final class HttpPackagistClient implements PackagistClient
{
    private const TIMEOUT_SECONDS = 5;

    /**
     * @return ?list<array<string, mixed>>
     */
    public function fetchPackageVersions(string $packageName): ?array
    {
        $raw = $this->httpGet(sprintf('https://repo.packagist.org/p2/%s.json', $packageName));
        if (null === $raw) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $packages = $data['packages'] ?? null;
        if (!\is_array($packages)) {
            return null;
        }

        $releases = $packages[$packageName] ?? null;
        if (!\is_array($releases)) {
            return null;
        }

        $result = [];
        foreach ($releases as $release) {
            if (\is_array($release)) {
                /** @var array<string, mixed> $release */
                $result[] = $release;
            }
        }

        return $result;
    }

    private function httpGet(string $url): ?string
    {
        if (\function_exists('curl_init')) {
            $ch = curl_init($url);
            if (false !== $ch) {
                curl_setopt_array($ch, [
                    \CURLOPT_RETURNTRANSFER => true,
                    \CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                    \CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                    \CURLOPT_FOLLOWLOCATION => true,
                    \CURLOPT_USERAGENT => 'sylius-mate-extension',
                ]);
                $response = curl_exec($ch);
                $status = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
                curl_close($ch);

                return \is_string($response) && 200 === $status ? $response : null;
            }
        }

        if (!filter_var(\ini_get('allow_url_fopen'), \FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => self::TIMEOUT_SECONDS,
                'header' => "User-Agent: sylius-mate-extension\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        return \is_string($response) ? $response : null;
    }
}
