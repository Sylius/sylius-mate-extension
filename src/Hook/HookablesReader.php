<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Hook;

use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\TwigHooks\Hookable\AbstractHookable;
use Sylius\TwigHooks\Hookable\HookableComponent;
use Sylius\TwigHooks\Hookable\HookableTemplate;
use Sylius\TwigHooks\Registry\HookablesRegistry;
use Sylius\TwigHooks\Twig\Runtime\HooksRuntime;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * @internal Reflects into HookablesRegistry::$hookables (no public enumerate API in sylius/twig-hooks 2.x).
 *
 * The registry service is private, so a runtime container will not hand it
 * out. It is reached through the public `twig` service instead: the hooks
 * Twig runtime holds the hook renderer, which holds the registry — found by
 * walking the object graph rather than by property names, so decorated
 * renderers (e.g. the debug comment renderer) and constructor changes do not
 * matter.
 */
final class HookablesReader
{
    private const SERVICE_ID = 'sylius_twig_hooks.registry.hookables';

    private const MAX_GRAPH_DEPTH = 5;

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
        $registry = $this->findRegistry();
        if (null === $registry) {
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

    private function findRegistry(): ?HookablesRegistry
    {
        $container = $this->host->getContainer();
        if ($container->has(self::SERVICE_ID)) {
            $registry = $container->get(self::SERVICE_ID);
            if ($registry instanceof HookablesRegistry) {
                return $registry;
            }
        }

        if (!$container->has('twig') || !class_exists(HooksRuntime::class)) {
            return null;
        }

        $twig = $container->get('twig');
        if (!$twig instanceof Environment) {
            return null;
        }

        try {
            $runtime = $twig->getRuntime(HooksRuntime::class);
        } catch (RuntimeError) {
            return null;
        }

        return $this->findInObjectGraph($runtime);
    }

    /**
     * Breadth-first over object properties (arrays one level deep) up to
     * MAX_GRAPH_DEPTH, returning the first HookablesRegistry instance.
     */
    private function findInObjectGraph(object $root): ?HookablesRegistry
    {
        /** @var list<array{0: object, 1: int}> $queue */
        $queue = [[$root, 0]];
        $seen = [];
        while ([] !== $queue) {
            [$object, $depth] = array_shift($queue);
            $id = spl_object_id($object);
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            if ($object instanceof HookablesRegistry) {
                return $object;
            }

            if ($depth >= self::MAX_GRAPH_DEPTH) {
                continue;
            }

            foreach ($this->propertyValues($object) as $value) {
                foreach (\is_array($value) ? $value : [$value] as $candidate) {
                    if (\is_object($candidate)) {
                        $queue[] = [$candidate, $depth + 1];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    private function propertyValues(object $object): array
    {
        $values = [];
        for ($class = new \ReflectionClass($object); false !== $class; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || !$property->isInitialized($object)) {
                    continue;
                }

                $values[] = $property->getValue($object);
            }
        }

        return $values;
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
