<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Output;

final class CallableDescriber
{
    public static function describe(mixed $callable): ?string
    {
        return match (true) {
            null === $callable => null,
            \is_string($callable) => $callable,
            $callable instanceof \Closure => self::describeClosure($callable),
            \is_array($callable) && 2 === \count($callable) => self::describeArray($callable),
            \is_object($callable) => $callable::class,
            default => null,
        };
    }

    private static function describeClosure(\Closure $closure): string
    {
        $reflection = new \ReflectionFunction($closure);
        $scope = $reflection->getClosureScopeClass();

        return null !== $scope ? $scope->getName() : ($reflection->getFileName() ?: 'Closure');
    }

    /**
     * @param array{0: mixed, 1: mixed} $callable
     */
    private static function describeArray(array $callable): string
    {
        [$target, $method] = $callable;
        $class = \is_object($target) ? $target::class : (string) $target;

        return sprintf('%s::%s', $class, (string) $method);
    }
}
