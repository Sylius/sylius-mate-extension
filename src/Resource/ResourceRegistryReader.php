<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Resource;

use Psr\Container\ContainerInterface;
use Sylius\MateExtension\Output\Envelope;
use Sylius\Resource\Metadata\Registry;
use Sylius\Resource\Metadata\RegistryInterface;
use Symfony\Component\DependencyInjection\Container;

/**
 * The resource registry service (`sylius.resource_registry`) is private in
 * SyliusResourceBundle, so a runtime container refuses to hand it out. The
 * bundle builds it from the public `sylius.resources` parameter (one
 * `addFromAliasAndConfiguration()` call per alias, see RegisterResourcesPass),
 * which is replayed here to get an equivalent registry.
 */
final class ResourceRegistryReader
{
    private const PARAMETER = 'sylius.resources';

    public static function read(ContainerInterface $container): ?RegistryInterface
    {
        if (!$container instanceof Container || !$container->hasParameter(self::PARAMETER)) {
            return null;
        }

        $resources = $container->getParameter(self::PARAMETER);
        if (!\is_array($resources)) {
            return null;
        }

        $registry = new Registry();
        foreach ($resources as $alias => $configuration) {
            if (\is_string($alias) && \is_array($configuration)) {
                $registry->addFromAliasAndConfiguration($alias, $configuration);
            }
        }

        return $registry;
    }

    /**
     * @return array<string, mixed>
     */
    public static function unavailable(): array
    {
        return Envelope::error(
            'registry_unavailable',
            sprintf('Parameter "%s" is not set — is SyliusResourceBundle enabled?', self::PARAMETER),
        );
    }
}
