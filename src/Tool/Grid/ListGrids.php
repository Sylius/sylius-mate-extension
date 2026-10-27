<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Grid;

use Sylius\MateExtension\Grid\GridDefinitionsReader;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;

final class ListGrids
{
    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_domain_list_grids',
        description: 'List configured Sylius grids: name, driver, resource class, fields, filters, actions. Filter by name prefix (e.g. "sylius_admin_"). Each item carries a "uri" readable with resources:read for the full grid definition.',
    )]
    public function __invoke(?string $name_prefix = null, int $limit = 50, ?string $cursor = null): array
    {
        $definitions = GridDefinitionsReader::read($this->host->getContainer());
        if (null === $definitions) {
            return Envelope::empty(GridDefinitionsReader::unavailableMessage());
        }

        $items = [];
        foreach ($definitions as $name => $definition) {
            if (null !== $name_prefix && !str_starts_with($name, $name_prefix)) {
                continue;
            }

            $driver = $definition['driver'] ?? [];
            $items[] = [
                'name' => $name,
                'driver' => \is_array($driver) ? ($driver['name'] ?? null) : null,
                'resource_class' => \is_array($driver) && \is_array($driver['options'] ?? null) ? ($driver['options']['class'] ?? null) : null,
                'fields' => isset($definition['fields']) && \is_array($definition['fields']) ? array_keys($definition['fields']) : [],
                'filters' => isset($definition['filters']) && \is_array($definition['filters']) ? array_keys($definition['filters']) : [],
                'actions' => isset($definition['actions']) && \is_array($definition['actions']) ? array_keys($definition['actions']) : [],
                'uri' => GridDefinition::URI_PREFIX . $name,
            ];
        }

        usort($items, static fn (array $a, array $b) => $a['name'] <=> $b['name']);
        $page = Envelope::paginate($items, $limit, $cursor);

        if ([] === $page['slice']) {
            return Envelope::empty('No grids matched. Call sylius_domain_resource_template to scaffold a new resource + grid stub.');
        }

        return Envelope::items($page['slice'], $page['cursor']);
    }
}
