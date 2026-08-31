<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Project;

use Psr\Container\ContainerInterface;
use Sylius\MateExtension\Kernel\ComposerPackageResolver;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class ServiceDecorators
{
    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_service_decorators',
        description: 'List every service in the host container that decorates a sylius.*/sylius_* id — {original_service_id, decorator_class, decorator_package, priority}. decorator_package may be null: decoration is orthogonal to plugins, a decorator can just as well be the host project\'s own customization with no plugin involved. Facts only: this does not say what a decorator implies, read decorator_class (Read/sylius_resource_inspect) to reason about that. Call before designing any listener/checker/service whose behavior might already be overridden.',
    )]
    public function __invoke(): array
    {
        return Envelope::guard(fn (): array => $this->detect());
    }

    /**
     * @return array<string, mixed>
     */
    private function detect(): array
    {
        $projectDir = HostProjectDir::resolve($this->host);
        $lock = ComposerPackageResolver::readLock($projectDir);

        $decorators = $this->detectDecorators($this->host->getContainer(), $projectDir, $lock);

        return Envelope::items($decorators, null, sprintf(
            'Found %d service(s) decorating a sylius.*/sylius_* id. Interpretation (what a decorator implies) is on you: read decorator_class.',
            \count($decorators),
        ));
    }

    /**
     * Parses the container's debug XML dump (Symfony framework-bundle writes
     * one on every debug-mode boot, same source `debug:container` reads
     * decoration info from) into a fresh ContainerBuilder so decoration
     * metadata — stripped from the compiled runtime container — is
     * available. Works for decorators registered any way (YAML, PHP config,
     * a compiler pass calling setDecoratedService()), not just YAML `decorates:`.
     *
     * @param array<string, array{version: string, type: ?string}> $lock
     *
     * @return list<array<string, mixed>>
     */
    private function detectDecorators(ContainerInterface $container, string $projectDir, array $lock): array
    {
        if (!$container instanceof Container) {
            return [];
        }

        if (!class_exists(ContainerBuilder::class) || !class_exists(XmlFileLoader::class)) {
            return [];
        }

        if (!$container->hasParameter('debug.container.dump')) {
            return [];
        }

        $dumpPath = $container->getParameter('debug.container.dump');
        if (!\is_string($dumpPath) || '' === $dumpPath || !is_file($dumpPath)) {
            return [];
        }

        $builder = new ContainerBuilder();

        try {
            (new XmlFileLoader($builder, new FileLocator(\dirname($dumpPath))))->load($dumpPath);
        } catch (\Throwable) {
            return [];
        }

        $decorators = [];
        foreach ($builder->getDefinitions() as $serviceId => $definition) {
            $decorated = $definition->getDecoratedService();
            if (null === $decorated) {
                continue;
            }

            $originalId = $decorated[0] ?? null;
            if (!\is_string($originalId)) {
                continue;
            }

            if (!str_starts_with($originalId, 'sylius.') && !str_starts_with($originalId, 'sylius_')) {
                continue;
            }

            $class = $definition->getClass();
            $package = \is_string($class) ? ComposerPackageResolver::resolve($class, $projectDir, $lock) : null;

            $decorators[] = [
                'original_service_id' => $originalId,
                'decorator_service_id' => $serviceId,
                'decorator_class' => $class,
                'decorator_package' => $package['name'] ?? null,
                'decorator_package_version' => $package['version'] ?? null,
                'priority' => $decorated[2] ?? 0,
            ];
        }

        usort($decorators, static fn (array $a, array $b): int => $a['original_service_id'] <=> $b['original_service_id']);

        return $decorators;
    }
}
