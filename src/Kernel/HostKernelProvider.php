<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Kernel;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class HostKernelProvider implements HostContainerProvider
{
    private ?KernelInterface $kernel = null;

    private ?string $containerXmlPath = null;

    private ?int $bootedContainerMtime = null;

    private float $lastFreshnessCheckAt = 0.0;

    private const FRESHNESS_CHECK_INTERVAL_SECONDS = 1.0;

    public function __construct(
        private readonly string $kernelClass = 'App\\Kernel',
        private readonly string $env = 'dev',
        private readonly bool $debug = true,
    ) {
    }

    public function getContainer(): ContainerInterface
    {
        return $this->getKernel()->getContainer();
    }

    public function getKernel(): KernelInterface
    {
        $this->rebootIfStale();

        if (null === $this->kernel) {
            $this->boot();
        }

        \assert(null !== $this->kernel);

        return $this->kernel;
    }

    public function shutdown(): void
    {
        if (null === $this->kernel) {
            return;
        }

        try {
            $this->kernel->shutdown();
        } catch (\Throwable) {
            // ignore — we are discarding the kernel anyway
        }

        $this->kernel = null;
        $this->containerXmlPath = null;
        $this->bootedContainerMtime = null;
    }

    private function boot(): void
    {
        $class = $this->resolveKernelClass();
        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf(
                'Host kernel class "%s" not found. sylius/ai-mate-domain must run inside a Symfony app with autoloaded Kernel. Set MATE_HOST_KERNEL env var to your kernel FQCN (e.g. "Acme\\Kernel").',
                $class,
            ));
        }

        $env = (string) ($_SERVER['MATE_HOST_ENV'] ?? $this->env);
        $debug = filter_var($_SERVER['MATE_HOST_DEBUG'] ?? $this->debug, \FILTER_VALIDATE_BOOL);

        $kernel = new $class($env, $debug);
        if (!$kernel instanceof KernelInterface) {
            throw new \RuntimeException(sprintf('"%s" must implement %s.', $this->kernelClass, KernelInterface::class));
        }

        $kernel->boot();
        $this->kernel = $kernel;
        $this->containerXmlPath = $this->resolveContainerXmlPath($kernel);
        $this->bootedContainerMtime = $this->containerXmlMtime();
        $this->lastFreshnessCheckAt = microtime(true);
    }

    private function resolveKernelClass(): string
    {
        $override = $_SERVER['MATE_HOST_KERNEL'] ?? $_ENV['MATE_HOST_KERNEL'] ?? getenv('MATE_HOST_KERNEL');
        if (\is_string($override) && '' !== $override) {
            return ltrim($override, '\\');
        }

        if (class_exists($this->kernelClass)) {
            return $this->kernelClass;
        }

        foreach ($this->discoverKernelCandidates() as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return $this->kernelClass;
    }

    /**
     * @return list<string>
     */
    private function discoverKernelCandidates(): array
    {
        $cwd = getcwd();
        if (false === $cwd) {
            return [];
        }

        $composerJson = $cwd . '/composer.json';
        if (!is_file($composerJson)) {
            return [];
        }

        $raw = @file_get_contents($composerJson);
        if (false === $raw) {
            return [];
        }

        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $candidates = [];
        $psr4 = $composer['autoload']['psr-4'] ?? [];
        if (!\is_array($psr4)) {
            return [];
        }

        foreach ($psr4 as $namespace => $paths) {
            if (!\is_string($namespace) || '' === $namespace) {
                continue;
            }

            $paths = (array) $paths;
            foreach ($paths as $path) {
                if (!\is_string($path)) {
                    continue;
                }

                $kernelFile = rtrim($cwd . '/' . $path, '/') . '/Kernel.php';
                if (is_file($kernelFile)) {
                    $candidates[] = rtrim($namespace, '\\') . '\\Kernel';
                }
            }
        }

        return $candidates;
    }

    private function rebootIfStale(): void
    {
        if (null === $this->kernel) {
            return;
        }

        if ((microtime(true) - $this->lastFreshnessCheckAt) < self::FRESHNESS_CHECK_INTERVAL_SECONDS) {
            return;
        }

        $this->lastFreshnessCheckAt = microtime(true);

        $currentMtime = $this->containerXmlMtime();
        if (null === $currentMtime || null === $this->bootedContainerMtime) {
            return;
        }

        if ($currentMtime > $this->bootedContainerMtime) {
            $this->shutdown();
        }
    }

    private function containerXmlMtime(): ?int
    {
        if (null === $this->containerXmlPath || !is_file($this->containerXmlPath)) {
            return null;
        }

        clearstatcache(true, $this->containerXmlPath);
        $mtime = @filemtime($this->containerXmlPath);

        return false === $mtime ? null : $mtime;
    }

    private function resolveContainerXmlPath(KernelInterface $kernel): ?string
    {
        $cacheDir = $kernel->getCacheDir();
        $candidates = glob($cacheDir . '/*KernelDevDebugContainer.xml') ?: [];
        if ([] !== $candidates) {
            return $candidates[0];
        }

        $generic = glob($cacheDir . '/*Container.xml') ?: [];

        return $generic[0] ?? null;
    }
}
