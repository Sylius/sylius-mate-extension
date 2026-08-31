<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Mailer;

use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;

final class CaptureStatus
{
    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_email_capture_status',
        description: 'Inspect the active MAILER_DSN: transport type, whether delivery is observable (smtp/mailpit) or null-routed (null://), and a recommended action when delivery cannot be asserted. Call before any Playwright email assertion.',
    )]
    public function __invoke(): array
    {
        return Envelope::guard(fn (): array => $this->inspect());
    }

    /**
     * @return array<string, mixed>
     */
    private function inspect(): array
    {
        $dsn = $this->detectMailerDsn();
        if (null === $dsn) {
            return Envelope::empty('MAILER_DSN is not set in the host environment.');
        }

        $scheme = $this->scheme($dsn);
        $observable = $this->isObservable($scheme);
        $captureUrl = $this->captureUrl($dsn, $scheme);
        $recommendation = $observable
            ? 'Email delivery is observable via this transport.'
            : 'Switch MAILER_DSN to smtp://mailpit:1025 (Mailpit recommended) or assert via the Mate Symfony profiler tools (X-Debug-Token + mailer collector). Then call sylius_admin_restock_via_http to trigger a stock change that yields a profile token.';

        return Envelope::items(
            [[
                'mailer_dsn' => $dsn,
                'scheme' => $scheme,
                'observable' => $observable,
                'capture_url' => $captureUrl,
                'recommendation' => $recommendation,
            ]],
            null,
            $observable
                ? sprintf('Mailer transport "%s" is observable.', $scheme)
                : sprintf('Mailer transport "%s" is NOT observable — email assertions will silently pass.', $scheme),
        );
    }

    private function detectMailerDsn(): ?string
    {
        $candidates = ['MAILER_DSN'];
        foreach ($candidates as $key) {
            if (isset($_ENV[$key]) && '' !== $_ENV[$key] && \is_string($_ENV[$key])) {
                return $_ENV[$key];
            }

            if (isset($_SERVER[$key]) && '' !== $_SERVER[$key] && \is_string($_SERVER[$key])) {
                return $_SERVER[$key];
            }

            $value = getenv($key);
            if (false !== $value && '' !== $value) {
                return $value;
            }
        }

        $container = $this->host->getContainer();
        if ($container instanceof \Symfony\Component\DependencyInjection\Container) {
            foreach (['env(MAILER_DSN)', 'mailer.dsn'] as $param) {
                if ($container->hasParameter($param)) {
                    $value = $container->getParameter($param);
                    if (\is_string($value) && '' !== $value) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    private function scheme(string $dsn): string
    {
        $pos = strpos($dsn, '://');

        return false === $pos ? $dsn : substr($dsn, 0, $pos);
    }

    private function isObservable(string $scheme): bool
    {
        return !\in_array($scheme, ['null', 'sendmail'], true);
    }

    private function captureUrl(string $dsn, string $scheme): ?string
    {
        if ('smtp' !== $scheme) {
            return null;
        }

        $host = parse_url($dsn, \PHP_URL_HOST);
        if (!\is_string($host)) {
            return null;
        }

        if ('mailpit' === $host || 'localhost' === $host) {
            return sprintf('http://%s:8025', $host);
        }

        return null;
    }
}
