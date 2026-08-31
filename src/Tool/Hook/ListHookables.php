<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Hook;

use Sylius\MateExtension\Hook\HookablesReader;
use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;

final class ListHookables
{
    public function __construct(
        private readonly HookablesReader $reader,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_hooks_list_hookables',
        description: 'List hookables attached to a given hook: name, template/component, priority, configuration.',
    )]
    public function __invoke(string $hook_name): array
    {
        if ('' === trim($hook_name)) {
            return Envelope::error('invalid_hook_name', 'Argument "hook_name" must not be empty.', 'Call sylius_hooks_list to see available hook names.');
        }

        $all = $this->reader->readAll();
        if (null === $all) {
            return Envelope::empty('SyliusTwigHooksBundle not enabled or registry not introspectable.');
        }

        if (!isset($all[$hook_name])) {
            return Envelope::empty(sprintf(
                'Hook "%s" has no hookables. Verify the name via sylius_hooks_list or sylius_hooks_find_for_template.',
                $hook_name,
            ));
        }

        $items = [];
        foreach ($all[$hook_name] as $hookable) {
            $items[] = $this->reader->describe($hookable);
        }

        usort($items, static fn (array $a, array $b) => $b['priority'] <=> $a['priority']);

        return Envelope::items($items);
    }
}
