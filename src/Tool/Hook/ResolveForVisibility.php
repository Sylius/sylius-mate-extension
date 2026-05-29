<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Hook;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Hook\HookablesReader;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;
use Sylius\TwigHooks\Hookable\HookableTemplate;
use Twig\Environment;

#[McpTool(
    name: 'sylius_hooks_resolve_for_visibility',
    description: 'Given a feature visibility intent (oos | in_stock | always | logged_in | admin_only) and a context substring (e.g. "product.show.content.info.summary"), return hook targets classified into safe_hooks and unsafe_hooks. Unsafe = parent template short-circuits the hook call inside a {% if %} branch that the visibility cannot satisfy. Use before deciding where to attach a back-in-stock-style widget.',
)]
final class ResolveForVisibility
{
    private const KNOWN_VISIBILITIES = ['oos', 'in_stock', 'always', 'logged_in', 'admin_only'];

    private const UNSAFE_GUARDS = [
        'in_stock' => ['variant.inStock', 'isAvailable', 'tracked == false', 'on_hand > 0'],
        'oos' => ['variant.outOfStock', '!isAvailable', 'on_hand == 0', 'on_hand <= 0'],
        'logged_in' => ['is_granted(\'IS_AUTHENTICATED', 'user is not null', 'app.user'],
        'admin_only' => ['is_granted(\'ROLE_ADMIN', 'is_granted(\'ROLE_SUPER_ADMIN'],
    ];

    public function __construct(
        private readonly HookablesReader $reader,
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $visibility, string $context, string $section = 'shop'): array
    {
        if (!\in_array($visibility, self::KNOWN_VISIBILITIES, true)) {
            return Envelope::error(
                'invalid_visibility',
                sprintf('Unknown visibility "%s".', $visibility),
                sprintf('Supported: %s.', implode(', ', self::KNOWN_VISIBILITIES)),
            );
        }

        if (!\in_array($section, ['shop', 'admin'], true)) {
            return Envelope::error('invalid_section', 'Argument "section" must be "shop" or "admin".');
        }

        return Envelope::guard(fn (): array => $this->resolve($visibility, $context, $section));
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(string $visibility, string $context, string $section): array
    {
        $all = $this->reader->readAll();
        if (null === $all) {
            return Envelope::empty('SyliusTwigHooksBundle not enabled or registry not introspectable.');
        }

        $prefix = sprintf('sylius_%s.', $section);
        $matches = [];
        foreach ($all as $hookName => $byName) {
            if (!str_starts_with($hookName, $prefix)) {
                continue;
            }

            if (!str_contains($hookName, $context)) {
                continue;
            }

            $template = $this->guessParentTemplate($byName, $hookName, $section);
            $analysis = $this->analyseTemplate($template, $hookName, $visibility);

            $matches[] = [
                'name' => $hookName,
                'parent_template' => $template,
                'safe' => $analysis['safe'],
                'reason' => $analysis['reason'],
                'detected_guards' => $analysis['guards'],
            ];
        }

        usort($matches, static fn (array $a, array $b) => $a['name'] <=> $b['name']);
        $safe = array_values(array_filter($matches, static fn (array $m): bool => $m['safe']));
        $unsafe = array_values(array_filter($matches, static fn (array $m): bool => !$m['safe']));

        $envelope = Envelope::items($matches, null, sprintf(
            '%d safe hook target(s), %d unsafe for visibility="%s".',
            \count($safe),
            \count($unsafe),
            $visibility,
        ));

        $envelope['visibility'] = $visibility;
        $envelope['section'] = $section;
        $envelope['context'] = $context;
        $envelope['safe_hooks'] = $safe;
        $envelope['unsafe_hooks'] = $unsafe;

        return $envelope;
    }

    /**
     * @param array<string, \Sylius\TwigHooks\Hookable\AbstractHookable> $byName
     */
    private function guessParentTemplate(array $byName, string $hookName, string $section): string
    {
        foreach ($byName as $hookable) {
            if ($hookable instanceof HookableTemplate) {
                return $hookable->template;
            }
        }

        $relative = str_replace([sprintf('sylius_%s.', $section), '.'], ['', '/'], $hookName);

        return sprintf('@Sylius%s/%s.html.twig', ucfirst($section), $relative);
    }

    /**
     * @return array{safe: bool, reason: ?string, guards: list<string>}
     */
    private function analyseTemplate(?string $templateName, string $hookName, string $visibility): array
    {
        $source = $this->readTemplate($templateName);
        if (null === $source) {
            return ['safe' => true, 'reason' => null, 'guards' => []];
        }

        $relevantGuards = $this->detectGuards($source, $visibility);
        if ([] === $relevantGuards) {
            return ['safe' => true, 'reason' => null, 'guards' => []];
        }

        return [
            'safe' => false,
            'reason' => sprintf('Parent template "%s" wraps the hook in a guard that excludes visibility "%s".', $templateName, $visibility),
            'guards' => $relevantGuards,
        ];
    }

    /**
     * @return list<string>
     */
    private function detectGuards(string $source, string $visibility): array
    {
        $patterns = self::UNSAFE_GUARDS[$visibility] ?? [];
        $found = [];
        foreach ($patterns as $needle) {
            if (str_contains($source, $needle)) {
                $found[] = $needle;
            }
        }

        return $found;
    }

    private function readTemplate(?string $name): ?string
    {
        if (null === $name) {
            return null;
        }

        try {
            $twig = $this->host->getContainer()->get('twig');
            if (!$twig instanceof Environment) {
                return null;
            }

            return $twig->getLoader()->getSourceContext($name)->getCode();
        } catch (\Throwable) {
            return null;
        }
    }
}
