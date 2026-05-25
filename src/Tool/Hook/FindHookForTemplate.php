<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Hook;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Hook\HookablesReader;
use Sylius\MateExtension\Output\Envelope;
use Sylius\TwigHooks\Hookable\HookableTemplate;

#[McpTool(
    name: 'sylius_hooks_find_for_template',
    description: 'Return the hooks that render a given Twig template path. Use to confirm UI placement before overriding a template.',
)]
final class FindHookForTemplate
{
    public function __construct(
        private readonly HookablesReader $reader,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $template_path): array
    {
        if ('' === trim($template_path)) {
            return Envelope::error('invalid_template_path', 'Argument "template_path" must not be empty.');
        }

        $all = $this->reader->readAll();
        if (null === $all) {
            return Envelope::empty('SyliusTwigHooksBundle not enabled or registry not introspectable.');
        }

        $needle = $this->normalize($template_path);
        $items = [];

        foreach ($all as $hookName => $byName) {
            foreach ($byName as $hookable) {
                if (!$hookable instanceof HookableTemplate) {
                    continue;
                }

                if ($this->normalize($hookable->template) !== $needle) {
                    continue;
                }

                $items[] = [
                    'hook_name' => $hookName,
                    'hookable_name' => $hookable->name,
                    'template' => $hookable->template,
                    'priority' => $hookable->priority(),
                ];
            }
        }

        if ([] === $items) {
            return Envelope::empty(sprintf(
                'No hook renders template "%s". Run sylius_hooks_list filtered by your shop/admin section to find the closest enclosing hook.',
                $template_path,
            ));
        }

        return Envelope::items($items);
    }

    private function normalize(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }
}
