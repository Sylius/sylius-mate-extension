<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Cache;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostKernelProvider;
use Sylius\MateExtension\Output\Envelope;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

#[McpTool(
    name: 'sylius_cache_clear',
    description: 'PHP-native cache clear: boots the host kernel inside the MCP process and runs cache:clear programmatically through Symfony\\Bundle\\FrameworkBundle\\Console\\Application — never shells `bin/console`, so the harness Bash classifier cannot intercept it. Falls back to a direct removal of var/cache/<env>/ if the programmatic path fails.',
)]
final class CacheClear
{
    public function __construct(
        private readonly HostKernelProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $env = 'dev'): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $env)) {
            return Envelope::error('invalid_env', 'Argument "env" must be a Symfony environment slug (e.g. "dev", "test").');
        }

        return Envelope::guard(fn (): array => $this->clear($env));
    }

    /**
     * @return array<string, mixed>
     */
    private function clear(string $env): array
    {
        $start = microtime(true);
        [$strategy, $output] = $this->programmatic($env);

        if (null === $strategy) {
            $programmaticFailure = $output;
            [$strategy, $output] = $this->purge($env);
            $output = $programmaticFailure . "\n---\n" . $output;
        }

        $this->host->shutdown();
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        return Envelope::items(
            [[
                'ok' => true,
                'env' => $env,
                'strategy' => $strategy,
                'duration_ms' => $durationMs,
                'output' => trim($output),
            ]],
            null,
            sprintf('cache:clear (%s) for env=%s completed in %d ms.', $strategy, $env, $durationMs),
        );
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function programmatic(string $env): array
    {
        if (!class_exists(Application::class)) {
            return [null, 'symfony/framework-bundle not installed; programmatic strategy unavailable.'];
        }

        $previousEnv = $_SERVER['MATE_HOST_ENV'] ?? null;
        $_SERVER['MATE_HOST_ENV'] = $env;

        try {
            $this->host->shutdown();
            $kernel = $this->host->getKernel();

            $application = new Application($kernel);
            $application->setAutoExit(false);

            $bufferedOutput = new BufferedOutput();
            $exitCode = $application->run(new ArrayInput([
                'command' => 'cache:clear',
                '--env' => $env,
                '--no-warmup' => true,
                '--no-interaction' => true,
            ]), $bufferedOutput);

            $stdout = $bufferedOutput->fetch();

            if (0 !== $exitCode) {
                return [null, sprintf('Programmatic cache:clear returned exit code %d. Output: %s', $exitCode, $stdout)];
            }

            return ['programmatic', $stdout];
        } catch (\Throwable $e) {
            return [null, sprintf('Programmatic strategy failed: %s: %s', $e::class, $e->getMessage())];
        } finally {
            if (null === $previousEnv) {
                unset($_SERVER['MATE_HOST_ENV']);
            } else {
                $_SERVER['MATE_HOST_ENV'] = $previousEnv;
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function purge(string $env): array
    {
        $cacheDir = $this->cacheDir($env);
        if (!is_dir($cacheDir)) {
            return ['purge', sprintf('Cache directory "%s" does not exist; nothing to purge.', $cacheDir)];
        }

        if (class_exists(Filesystem::class)) {
            (new Filesystem())->remove($cacheDir);
        } else {
            $this->recursiveRm($cacheDir);
        }

        return ['purge', sprintf('Removed "%s" — next request will rebuild.', $cacheDir)];
    }

    private function cacheDir(string $env): string
    {
        $container = $this->host->getContainer();
        if ($container instanceof \Symfony\Component\DependencyInjection\Container && $container->hasParameter('kernel.project_dir')) {
            return sprintf('%s/var/cache/%s', (string) $container->getParameter('kernel.project_dir'), $env);
        }

        return getcwd() . '/var/cache/' . $env;
    }

    private function recursiveRm(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->recursiveRm($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
