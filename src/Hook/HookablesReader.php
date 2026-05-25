<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Hook;

use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\TwigHooks\Hookable\AbstractHookable;
use Sylius\TwigHooks\Hookable\HookableComponent;
use Sylius\TwigHooks\Hookable\HookableTemplate;
use Sylius\TwigHooks\Registry\HookablesRegistry;

/**
 * @internal Reflects into HookablesRegistry::$hookables (no public enumerate API in sylius/twig-hooks 2.x).
 */
final class HookablesReader
{
    private const SERVICE_ID = 'sylius_twig_hooks.registry.hookables';

    /** @var array<string, array<string, AbstractHookable>>|null */
    private ?array $cache = null;

    private bool $resolved = false;

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, array<string, AbstractHookable>>|null
     */
    public function readAll(): ?array
    {
        if ($this->resolved) {
            return $this->cache;
        }

        $this->resolved = true;
        $container = $this->host->getContainer();
        if (!$container->has(self::SERVICE_ID)) {
            return $this->cache = null;
        }

        $registry = $container->get(self::SERVICE_ID);
        if (!$registry instanceof HookablesRegistry) {
            return $this->cache = null;
        }

        $reflection = new \ReflectionClass($registry);
        if (!$reflection->hasProperty('hookables')) {
            return $this->cache = null;
        }

        /** @var array<string, array<string, AbstractHookable>> $value */
        $value = $reflection->getProperty('hookables')->getValue($registry);

        return $this->cache = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(AbstractHookable $hookable): array
    {
        $base = [
            'id' => $hookable->id,
            'hook_name' => $hookable->hookName,
            'name' => $hookable->name,
            'priority' => $hookable->priority(),
            'context' => $hookable->context,
            'configuration' => $hookable->configuration,
        ];

        if ($hookable instanceof HookableTemplate) {
            $base['kind'] = 'template';
            $base['template'] = $hookable->template;
        } elseif ($hookable instanceof HookableComponent) {
            $base['kind'] = 'component';
            $base['component'] = $hookable->component;
            $base['props'] = $hookable->props;
        } else {
            $base['kind'] = 'other';
            $base['class'] = $hookable::class;
        }

        return $base;
    }
}
