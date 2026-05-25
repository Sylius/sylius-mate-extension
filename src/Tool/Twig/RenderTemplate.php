<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Twig;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;
use Twig\Environment;
use Twig\Error\Error as TwigError;

#[McpTool(
    name: 'sylius_test_render_template',
    description: 'Render a host Twig template with the given context (associative array) and return the output, or a structured error if rendering fails. Use to smoke-test a template for missing functions, syntax errors, undefined variables before running the app.',
)]
final class RenderTemplate
{
    private const SERVICE_ID = 'twig';

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function __invoke(string $template, array $context = []): array
    {
        if ('' === trim($template)) {
            return Envelope::error('invalid_template', 'Argument "template" must not be empty.');
        }

        $twig = $this->host->getContainer()->get(self::SERVICE_ID);
        if (!$twig instanceof Environment) {
            return Envelope::error('twig_unavailable', 'Service "twig" is not a Twig\\Environment.');
        }

        if (!$twig->getLoader()->exists($template)) {
            return Envelope::error(
                'template_not_found',
                sprintf('Twig loader cannot resolve "%s".', $template),
                'Check the template path namespace and that the bundle/templates dir is registered.',
            );
        }

        try {
            $output = $twig->render($template, $context);
        } catch (TwigError $e) {
            return Envelope::error(
                'render_failed',
                $e->getMessage(),
                sprintf(
                    'Failed in %s:%d. Check sylius_twig_list_functions for available helpers and sylius_hooks_list for the hook surface.',
                    (string) ($e->getSourceContext()?->getName() ?? $template),
                    $e->getTemplateLine(),
                ),
            );
        } catch (\Throwable $e) {
            return Envelope::error('render_exception', $e->getMessage());
        }

        return Envelope::items(
            [[
                'template' => $template,
                'output' => $output,
                'size_bytes' => \strlen($output),
            ]],
            null,
            sprintf('Rendered "%s" in %d bytes.', $template, \strlen($output)),
        );
    }
}
