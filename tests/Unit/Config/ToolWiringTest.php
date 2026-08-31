<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Guards against a tool class that exists on disk (so PHPStan/PHPUnit are
 * happy) but was never added to the $tools map in config/config.php. Since
 * Mate 0.13 discovery auto-registers unwired handler classes with plain
 * autowiring, but this extension deliberately wires every tool explicitly
 * (deterministic constructor arguments, no reliance on autowiring being able
 * to resolve them) — so every #[MateTool] class still needs an explicit
 * $services->set() in config.php, and this test keeps that invariant.
 */
final class ToolWiringTest extends TestCase
{
    public function testEveryMateToolClassIsRegisteredAndInstantiable(): void
    {
        $root = \dirname(__DIR__, 3);

        $container = new ContainerBuilder();
        (new PhpFileLoader($container, new FileLocator($root . '/config')))->load('config.php');
        $container->compile();

        $missing = [];
        foreach ($this->findMateToolClasses($root . '/src/Tool') as $class) {
            if (!$container->has($class)) {
                $missing[] = $class;

                continue;
            }

            try {
                $container->get($class);
            } catch (\Throwable $e) {
                self::fail(sprintf('"%s" is registered but fails to instantiate: %s', $class, $e->getMessage()));
            }
        }

        self::assertSame([], $missing, sprintf(
            "The following #[MateTool] class(es) exist but aren't wired in config/config.php:\n- %s",
            implode("\n- ", $missing),
        ));
    }

    /**
     * @return list<class-string>
     */
    private function findMateToolClasses(string $toolDir): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($toolDir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $class = $this->classFromFile($file->getPathname(), $toolDir);
            if (null === $class || !class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ([] !== $method->getAttributes(MateTool::class)) {
                    $classes[] = $class;

                    break;
                }
            }
        }

        return $classes;
    }

    private function classFromFile(string $path, string $toolDir): ?string
    {
        $relative = substr($path, \strlen($toolDir) + 1);
        $relative = substr($relative, 0, -\strlen('.php'));

        return 'Sylius\\MateExtension\\Tool\\' . str_replace('/', '\\', $relative);
    }
}
