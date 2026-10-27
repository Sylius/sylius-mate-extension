<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Hook;

use Sylius\MateExtension\Hook\HookablesReader;
use Sylius\MateExtension\Output\Envelope;
use Sylius\MateExtension\Resource\ResourceUri;
use Symfony\AI\Mate\Attribute\MateTool;

final class ListHooks
{
    public function __construct(
        private readonly HookablesReader $reader,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_hooks_list',
        description: 'List registered Sylius TwigHooks with hookable counts. Filter by name prefix (e.g. "sylius_shop.product."). Each item carries a "uri" readable with resources:read for the hook\'s hookables.',
    )]
    public function __invoke(?string $name_prefix = null, int $limit = 50, ?string $cursor = null): array
    {
        $hookables = $this->reader->readAll();
        if (null === $hookables) {
            return Envelope::empty('SyliusTwigHooksBundle not enabled or registry not introspectable.');
        }

        $items = [];
        foreach ($hookables as $hookName => $byName) {
            if (null !== $name_prefix && !str_starts_with($hookName, $name_prefix)) {
                continue;
            }

            $items[] = [
                'name' => $hookName,
                'hookable_count' => \count($byName),
                'uri' => ResourceUri::build(ListHookables::URI_PREFIX, $hookName),
            ];
        }

        usort($items, static fn (array $a, array $b) => $a['name'] <=> $b['name']);
        $page = Envelope::paginate($items, $limit, $cursor);

        if ([] === $page['slice']) {
            return Envelope::empty('No hooks matched. Use sylius_hooks_find_for_template to locate the hook enclosing a template path.');
        }

        return Envelope::items($page['slice'], $page['cursor']);
    }
}
