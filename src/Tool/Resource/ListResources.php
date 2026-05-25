<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Resource;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;
use Sylius\Resource\Metadata\MetadataInterface;
use Sylius\Resource\Metadata\RegistryInterface;

#[McpTool(
    name: 'sylius_domain_list_resources',
    description: 'List all registered Sylius resources (alias, model, interface, repository, factory, form). Filter by alias prefix.',
)]
final class ListResources
{
    private const SERVICE_ID = 'sylius.resource_registry';

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $alias_prefix = null, int $limit = 50, ?string $cursor = null): array
    {
        return Envelope::guard(fn (): array => $this->doList($alias_prefix, $limit, $cursor));
    }

    /**
     * @return array<string, mixed>
     */
    private function doList(?string $alias_prefix, int $limit, ?string $cursor): array
    {
        $registry = $this->host->getContainer()->get(self::SERVICE_ID);
        if (!$registry instanceof RegistryInterface) {
            return Envelope::error(
                'registry_unavailable',
                sprintf('Service "%s" is not a %s.', self::SERVICE_ID, RegistryInterface::class),
            );
        }

        $items = [];
        foreach ($registry->getAll() as $metadata) {
            if (!$metadata instanceof MetadataInterface) {
                continue;
            }

            $alias = $metadata->getAlias();
            if (null !== $alias_prefix && !str_starts_with($alias, $alias_prefix)) {
                continue;
            }

            $items[] = [
                'alias' => $alias,
                'application' => $metadata->getApplicationName(),
                'name' => $metadata->getName(),
                'plural_name' => $metadata->getPluralName(),
                'driver' => $metadata->getDriver(),
                'classes' => $this->extractClasses($metadata),
                'templates_namespace' => $metadata->getTemplatesNamespace(),
            ];
        }

        usort($items, static fn (array $a, array $b) => $a['alias'] <=> $b['alias']);

        $page = Envelope::paginate($items, $limit, $cursor);

        if ([] === $page['slice']) {
            return Envelope::empty(sprintf(
                'No resources matched%s. Try without alias_prefix or call sylius_domain_resource_template to scaffold one.',
                null !== $alias_prefix ? sprintf(' prefix "%s"', $alias_prefix) : '',
            ));
        }

        return Envelope::items($page['slice'], $page['cursor']);
    }

    /**
     * @return array<string, class-string>
     */
    private function extractClasses(MetadataInterface $metadata): array
    {
        $classes = [];
        foreach (['model', 'interface', 'repository', 'factory', 'form', 'controller'] as $name) {
            if ($metadata->hasClass($name)) {
                $classes[$name] = $metadata->getClass($name);
            }
        }

        return $classes;
    }
}
