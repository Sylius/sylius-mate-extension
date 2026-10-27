<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Grid;

use Sylius\MateExtension\Grid\GridDefinitionsReader;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;
use Sylius\MateExtension\Resource\ResourceLinker;
use Sylius\MateExtension\Resource\ResourceUri;
use Symfony\AI\Mate\Attribute\MateResourceTemplate;

final class GridDefinition
{
    public const URI_PREFIX = 'sylius://grid/';

    public function __construct(
        private readonly HostContainerProvider $host,
        private readonly ResourceLinker $linker,
    ) {
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    #[MateResourceTemplate(
        uriTemplate: self::URI_PREFIX . '{name}',
        name: 'sylius_grid',
        description: 'Full definition of a configured Sylius grid after config merge: driver, sorting, limits, fields, filters and actions with their options. Take the URI from the "uri" field of sylius_domain_list_grids items.',
        mimeType: 'text/plain',
    )]
    public function read(string $name): array
    {
        $name = ResourceUri::decode($name);

        return Envelope::resource(ResourceUri::build(self::URI_PREFIX, $name), $this->describe($name));
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(string $name): array
    {
        $definitions = GridDefinitionsReader::read($this->host->getContainer());
        if (null === $definitions) {
            return Envelope::empty(GridDefinitionsReader::unavailableMessage());
        }

        if (!isset($definitions[$name])) {
            return Envelope::empty(sprintf(
                'Grid "%s" is not configured. Verify the name via sylius_domain_list_grids.',
                $name,
            ));
        }

        return Envelope::items([['name' => $name] + $definitions[$name] + ['links' => $this->linker->forGrid($name)]]);
    }
}
