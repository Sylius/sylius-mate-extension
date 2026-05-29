<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Output;

use Webmozart\Assert\Assert;

final class CallableDescriber
{
    public static function describe(mixed $callable): ?string
    {
        if (null === $callable) {
            return null;
        }

        if (\is_string($callable)) {
            return $callable;
        }

        if ($callable instanceof \Closure) {
            return self::describeClosure($callable);
        }

        if (\is_array($callable) && 2 === \count($callable) && isset($callable[0], $callable[1])) {
            $target = $callable[0];
            $method = $callable[1];
            Assert::string($method);
            if (\is_object($target)) {
                $class = $target::class;
            } else {
                Assert::string($target);
                $class = $target;
            }

            return sprintf('%s::%s', $class, $method);
        }

        if (\is_object($callable)) {
            return $callable::class;
        }

        return null;
    }

    private static function describeClosure(\Closure $closure): string
    {
        $reflection = new \ReflectionFunction($closure);
        $scope = $reflection->getClosureScopeClass();

        return null !== $scope ? $scope->getName() : ($reflection->getFileName() ?: 'Closure');
    }
}
