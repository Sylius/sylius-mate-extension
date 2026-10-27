<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Grid;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;

/**
 * SyliusGridBundle compiles every configured grid into the public
 * `sylius.grids_definitions` parameter; the tools read it from there.
 */
final class GridDefinitionsReader
{
    public const PARAMETER = 'sylius.grids_definitions';

    /**
     * @return array<string, array<string, mixed>>|null null when SyliusGridBundle is not enabled
     */
    public static function read(ContainerInterface $container): ?array
    {
        if (!$container instanceof Container || !$container->hasParameter(self::PARAMETER)) {
            return null;
        }

        $definitions = [];
        foreach ((array) $container->getParameter(self::PARAMETER) as $name => $definition) {
            if (\is_string($name) && \is_array($definition)) {
                /** @var array<string, mixed> $definition */
                $definitions[$name] = $definition;
            }
        }

        return $definitions;
    }

    public static function unavailableMessage(): string
    {
        return sprintf('Parameter "%s" is not set. SyliusGridBundle may not be enabled.', self::PARAMETER);
    }
}
