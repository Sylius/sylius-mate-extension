<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Twig;

use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\CallableDescriber;
use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;
use Twig\Environment;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

final class VerifyFunction
{
    private const SERVICE_ID = 'twig';

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_twig_function_verify',
        description: 'Strict verification of a Twig callable by exact name. Returns kind (function/filter/test), origin class, and reflected signature (parameter list). Optional kind arg narrows the search.',
    )]
    public function __invoke(string $name, ?string $kind = null): array
    {
        if ('' === trim($name)) {
            return Envelope::error('invalid_name', 'Argument "name" must not be empty.');
        }

        $kind = null === $kind ? null : strtolower($kind);
        if (null !== $kind && !\in_array($kind, ['function', 'filter', 'test'], true)) {
            return Envelope::error('invalid_kind', 'Argument "kind" must be one of: function, filter, test.');
        }

        $twig = $this->host->getContainer()->get(self::SERVICE_ID);
        if (!$twig instanceof Environment) {
            return Envelope::error('twig_unavailable', 'Service "twig" is not a Twig\\Environment.');
        }

        $matches = [];

        if (null === $kind || 'function' === $kind) {
            $function = $twig->getFunction($name);
            if ($function instanceof TwigFunction) {
                $matches[] = $this->describe('function', $function);
            }
        }

        if (null === $kind || 'filter' === $kind) {
            $filter = $twig->getFilter($name);
            if ($filter instanceof TwigFilter) {
                $matches[] = $this->describe('filter', $filter);
            }
        }

        if (null === $kind || 'test' === $kind) {
            $test = $twig->getTest($name);
            if ($test instanceof TwigTest) {
                $matches[] = $this->describe('test', $test);
            }
        }

        if ([] === $matches) {
            return Envelope::empty(sprintf(
                'No Twig %s named "%s" is registered. Do not use it in templates. Call sylius_twig_list_functions name_prefix="%s" to find similar.',
                $kind ?? 'callable',
                $name,
                substr($name, 0, min(\strlen($name), 8)),
            ));
        }

        return Envelope::items($matches);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $kind, TwigFunction|TwigFilter|TwigTest $item): array
    {
        $callable = $item->getCallable();

        return [
            'kind' => $kind,
            'name' => $item->getName(),
            'origin' => CallableDescriber::describe($callable),
            'parameters' => $this->reflectParameters($callable),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reflectParameters(mixed $callable): array
    {
        if (null === $callable) {
            return [];
        }

        try {
            $reflection = $this->reflectCallable($callable);
        } catch (\ReflectionException) {
            return [];
        }

        if (null === $reflection) {
            return [];
        }

        $params = [];
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $params[] = [
                'name' => $param->getName(),
                'type' => $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type,
                'optional' => $param->isOptional(),
                'variadic' => $param->isVariadic(),
            ];
        }

        return $params;
    }

    private function reflectCallable(mixed $callable): ?\ReflectionFunctionAbstract
    {
        if ($callable instanceof \Closure) {
            return new \ReflectionFunction($callable);
        }

        if (\is_string($callable) && function_exists($callable)) {
            return new \ReflectionFunction($callable);
        }

        if (\is_array($callable) && 2 === \count($callable) && isset($callable[0], $callable[1])) {
            $target = $callable[0];
            $method = $callable[1];
            if (!\is_string($method)) {
                return null;
            }
            if (\is_object($target)) {
                $class = $target::class;
            } elseif (\is_string($target)) {
                $class = $target;
            } else {
                return null;
            }

            return new \ReflectionMethod($class, $method);
        }

        if (\is_object($callable) && method_exists($callable, '__invoke')) {
            return new \ReflectionMethod($callable, '__invoke');
        }

        return null;
    }
}
