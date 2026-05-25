<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Twig;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\CallableDescriber;
use Sylius\MateExtension\Output\Envelope;
use Twig\Environment;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

#[McpTool(
    name: 'sylius_twig_list_functions',
    description: 'List registered Twig functions, filters and tests with their origin extension. Filter by name prefix (e.g. "sylius_"). Use to verify a Twig helper exists before referencing it.',
)]
final class ListFunctions
{
    private const SERVICE_ID = 'twig';

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $name_prefix = null, ?string $kind = null, int $limit = 100, ?string $cursor = null): array
    {
        $kind = null === $kind ? null : strtolower($kind);
        if (null !== $kind && !\in_array($kind, ['function', 'filter', 'test'], true)) {
            return Envelope::error('invalid_kind', 'Argument "kind" must be one of: function, filter, test (or omitted).');
        }

        $twig = $this->host->getContainer()->get(self::SERVICE_ID);
        if (!$twig instanceof Environment) {
            return Envelope::error('twig_unavailable', 'Service "twig" is not a Twig\\Environment.');
        }

        $items = [];

        if (null === $kind || 'function' === $kind) {
            foreach ($twig->getFunctions() as $function) {
                $this->collect($items, 'function', $function, $name_prefix);
            }
        }

        if (null === $kind || 'filter' === $kind) {
            foreach ($twig->getFilters() as $filter) {
                $this->collect($items, 'filter', $filter, $name_prefix);
            }
        }

        if (null === $kind || 'test' === $kind) {
            foreach ($twig->getTests() as $test) {
                $this->collect($items, 'test', $test, $name_prefix);
            }
        }

        usort($items, static fn (array $a, array $b) => [$a['kind'], $a['name']] <=> [$b['kind'], $b['name']]);
        $page = Envelope::paginate($items, $limit, $cursor);

        if ([] === $page['slice']) {
            return Envelope::empty(sprintf(
                'No Twig %s matched%s.',
                $kind ?? 'callable',
                null !== $name_prefix ? sprintf(' prefix "%s"', $name_prefix) : '',
            ));
        }

        return Envelope::items($page['slice'], $page['cursor']);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function collect(array &$items, string $kind, TwigFunction|TwigFilter|TwigTest $item, ?string $namePrefix): void
    {
        $name = $item->getName();
        if (null !== $namePrefix && !str_starts_with($name, $namePrefix)) {
            return;
        }

        $items[] = [
            'kind' => $kind,
            'name' => $name,
            'origin' => CallableDescriber::describe($item->getCallable()),
        ];
    }
}
