<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Config;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Guards against the exact bug that slipped through once already: a class
 * carrying #[McpTool] that exists on disk (so PHPStan/PHPUnit are happy)
 * but was never added to the $tools map in config/config.php, so the real
 * MCP server can never instantiate — let alone call — it. scan-dirs in
 * composer.json's extra.ai-mate does NOT auto-register services; every
 * #[McpTool] class needs an explicit $services->set() in config.php.
 */
final class ToolWiringTest extends TestCase
{
    public function testEveryMcpToolClassIsRegisteredAndInstantiable(): void
    {
        $root = \dirname(__DIR__, 3);

        $container = new ContainerBuilder();
        (new PhpFileLoader($container, new FileLocator($root . '/config')))->load('config.php');
        $container->compile();

        $missing = [];
        foreach ($this->findMcpToolClasses($root . '/src/Tool') as $class) {
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
            "The following #[McpTool] class(es) exist but aren't wired in config/config.php, so the MCP server can never call them:\n- %s",
            implode("\n- ", $missing),
        ));
    }

    /**
     * @return list<class-string>
     */
    private function findMcpToolClasses(string $toolDir): array
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
            if ([] !== $reflection->getAttributes(McpTool::class)) {
                $classes[] = $class;
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
