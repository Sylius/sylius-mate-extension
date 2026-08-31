<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Email;

use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;

final class EmailTemplateSkeleton
{
    public function __construct(
        private readonly string $scaffoldDir,
    ) {
    }

    /**
     * @param list<string> $context_vars
     *
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_email_template_skeleton',
        description: 'Emit a working twig template extending @SyliusCore/Email/layout.html.twig plus the matching sylius_mailer yaml entry for a new email code. Args: code (snake_case), context_vars (list of variable names referenced in the body).',
    )]
    public function __invoke(string $code, array $context_vars = []): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            return Envelope::error('invalid_code', 'Argument "code" must be snake_case, e.g. "back_in_stock".');
        }

        $vars = [
            '{{ code }}' => $code,
            '{{ context_vars }}' => '' === ($joined = implode(', ', $context_vars)) ? '(none provided)' : $joined,
        ];

        $template = @file_get_contents($this->scaffoldDir . '/email_template.html.twig.tpl');
        if (false === $template) {
            return Envelope::error('template_missing', 'Scaffold template email_template.html.twig.tpl not found.');
        }

        $yaml = sprintf(
            "sylius_mailer:\n    emails:\n        %s:\n            subject: app.email.%s.subject\n            template: 'email/%s.html.twig'\n",
            $code,
            $code,
            $code,
        );

        return Envelope::items(
            [
                [
                    'kind' => 'email_template',
                    'suggested_path' => sprintf('templates/email/%s.html.twig', $code),
                    'body' => strtr($template, $vars),
                ],
                [
                    'kind' => 'mailer_config',
                    'suggested_path' => sprintf('config/packages/sylius_mailer_%s.yaml', $code),
                    'body' => $yaml,
                ],
                [
                    'kind' => 'translation_keys',
                    'suggested_path' => 'translations/messages.<locale>.yaml',
                    'body' => sprintf(
                        "app:\n    email:\n        %s:\n            subject: TODO subject for %s\n",
                        $code,
                        $code,
                    ),
                ],
            ],
            null,
            sprintf(
                'Write both files, register the translation under app.email.%s.subject via sylius_translation_create, then call sylius_mailer_verify_template code="%s".',
                $code,
                $code,
            ),
        );
    }
}
