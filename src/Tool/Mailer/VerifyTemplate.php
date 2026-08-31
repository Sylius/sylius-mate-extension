<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Mailer;

use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;
use Twig\Environment;

final class VerifyTemplate
{
    private const PARAMETER = 'sylius.mailer.emails';

    private const TWIG = 'twig';

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_mailer_verify_template',
        description: 'For a registered Sylius mailer email code, verify the configured Twig template exists in the loader and is not empty. Catches the L + empty-template gap before runtime.',
    )]
    public function __invoke(string $code): array
    {
        if ('' === trim($code)) {
            return Envelope::error('invalid_code', 'Argument "code" must not be empty.');
        }

        $container = $this->host->getContainer();
        if (!$container instanceof \Symfony\Component\DependencyInjection\Container || !$container->hasParameter(self::PARAMETER)) {
            return Envelope::error('mailer_unavailable', 'Parameter "sylius.mailer.emails" is not set. SyliusMailerBundle may not be enabled.');
        }

        /** @var array<string, array<string, mixed>> $config */
        $config = $container->getParameter(self::PARAMETER);
        if (!isset($config[$code])) {
            return Envelope::error(
                'unknown_email_code',
                sprintf('Email code "%s" is not registered.', $code),
                sprintf('Email code "%s" not found in sylius_mailer.emails parameter.', $code),
            );
        }

        $template = $config[$code]['template'] ?? null;
        if (!\is_string($template) || '' === $template) {
            return Envelope::error('template_unset', sprintf('No "template" configured for email code "%s".', $code));
        }

        $twig = $container->get(self::TWIG);
        if (!$twig instanceof Environment) {
            return Envelope::error('twig_unavailable', 'Service "twig" is not a Twig\\Environment.');
        }

        $loader = $twig->getLoader();
        $checks = [
            ['name' => 'template_exists', 'ok' => $loader->exists($template), 'hint' => null],
        ];

        $sourcePath = null;
        $sourceLength = 0;
        if ($checks[0]['ok']) {
            try {
                $source = $loader->getSourceContext($template);
                $sourcePath = $source->getPath();
                $sourceLength = \strlen($source->getCode());
            } catch (\Twig\Error\LoaderError $e) {
                $checks[0]['ok'] = false;
                $checks[0]['hint'] = $e->getMessage();
            }
        } else {
            $checks[0]['hint'] = sprintf('Loader cannot resolve "%s". Check the template path namespace and that the bundle/templates dir is registered.', $template);
        }

        $checks[] = [
            'name' => 'template_non_empty',
            'ok' => $sourceLength > 0,
            'hint' => $sourceLength > 0 ? null : sprintf('Template "%s" exists but has 0 bytes — write the content.', $template),
        ];

        $envelope = Envelope::items($checks, null, [] === array_filter($checks, static fn (array $c): bool => false === $c['ok'])
            ? sprintf('Mailer template "%s" for code "%s" is OK (%d bytes).', $template, $code, $sourceLength)
            : sprintf('Mailer template "%s" for code "%s" has issues — see items.', $template, $code));

        $envelope['template'] = [
            'logical_name' => $template,
            'resolved_path' => $sourcePath,
            'size_bytes' => $sourceLength,
        ];

        return $envelope;
    }
}
