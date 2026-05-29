<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Grid;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;

#[McpTool(
    name: 'sylius_grid_actions_audit',
    description: 'Validate the actions: block of a grid. Warns on common mistakes: main.delete (delete should live under item.* per row), item-only types in main, missing main.create, etc.',
)]
final class ActionsAudit
{
    private const PARAMETER = 'sylius.grids_definitions';

    private const ITEM_ONLY_TYPES = ['update', 'delete', 'show'];

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $grid_name): array
    {
        if ('' === trim($grid_name)) {
            return Envelope::error('invalid_grid_name', 'Argument "grid_name" must not be empty.');
        }

        return Envelope::guard(fn (): array => $this->audit($grid_name));
    }

    /**
     * @return array<string, mixed>
     */
    private function audit(string $grid_name): array
    {
        $container = $this->host->getContainer();
        if (!$container instanceof \Symfony\Component\DependencyInjection\Container || !$container->hasParameter(self::PARAMETER)) {
            return Envelope::error('grids_unavailable', 'Parameter "sylius.grids_definitions" is not set.');
        }

        /** @var array<string, array<string, mixed>> $definitions */
        $definitions = $container->getParameter(self::PARAMETER);
        if (!isset($definitions[$grid_name])) {
            return Envelope::empty(sprintf('Grid "%s" is not registered. Call sylius_domain_list_grids to find it.', $grid_name));
        }

        $actions = $definitions[$grid_name]['actions'] ?? [];
        if (!\is_array($actions)) {
            return Envelope::error('invalid_actions', sprintf('Grid "%s" actions: is not an array.', $grid_name));
        }

        $main = \is_array($actions['main'] ?? null) ? $actions['main'] : [];
        $item = \is_array($actions['item'] ?? null) ? $actions['item'] : [];

        $warnings = [];

        foreach ($main as $name => $config) {
            $type = \is_array($config) ? ($config['type'] ?? null) : $config;
            if (\is_string($type) && \in_array($type, self::ITEM_ONLY_TYPES, true)) {
                $warnings[] = sprintf(
                    'Action "main.%s" has type "%s" — this is item-scoped. Move under actions.item.%s.',
                    (string) $name,
                    $type,
                    (string) $name,
                );
            }
        }

        if (!isset($main['create'])) {
            $warnings[] = 'No actions.main.create — admins cannot create new resources from the grid toolbar.';
        }

        foreach ($item as $name => $config) {
            $type = \is_array($config) ? ($config['type'] ?? null) : $config;
            if (\is_string($type) && 'create' === $type) {
                $warnings[] = sprintf(
                    'Action "item.%s" has type "create" — create is a toolbar action; move under actions.main.create.',
                    (string) $name,
                );
            }
        }

        return Envelope::items(
            [[
                'grid' => $grid_name,
                'main' => array_keys($main),
                'item' => array_keys($item),
                'warnings' => $warnings,
            ]],
            null,
            [] === $warnings
                ? sprintf('Grid "%s" actions look clean.', $grid_name)
                : sprintf('%d warning(s) on grid "%s" actions block.', \count($warnings), $grid_name),
        );
    }
}
