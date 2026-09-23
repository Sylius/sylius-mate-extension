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
        description: 'List every service in the host container that decorates a sylius.*/sylius_* id — {original_service_id, original_class, decorator_class, decorator_package, chain_position, chain_length}. chain_position 1 is the outermost decorator (what the container hands out); the highest position wraps the original directly (highest decoration_priority). decorator_package may be null: decoration is orthogonal to plugins, a decorator can just as well be the host project\'s own customization with no plugin involved. Facts only: this does not say what a decorator implies, read decorator_class (Read/sylius_resource_inspect) to reason about that. Call before designing any listener/checker/service whose behavior might already be overridden.',
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
     * Reads decoration from the container's debug XML dump (written by
     * framework-bundle on every debug-mode boot, the same source
     * `debug:container` uses). The compiled container no longer knows
     * `decorates:` — DecoratorServicePass resolves it before the dump — but it
     * leaves a `container.decorator` tag on the OUTERMOST decorator of every
     * decorated id (`id` = decorated service, `inner` = where the original
     * definition was moved) and `<decorator id>.inner` aliases from each
     * decorator to the next one. Priorities are gone too; chain_position is
     * what they produced. Custom decoration_inner_name is not followed: its
     * alias is resolved away before the dump, so such a chain stops early.
     *
     * @param array<string, array{version: string, type: ?string}> $lock
     *
     * @return list<array<string, mixed>>
     */
    private function detectDecorators(ContainerInterface $container, string $projectDir, array $lock): array
    {
        if (!$container instanceof Container || !$container->hasParameter('debug.container.dump')) {
            return [];
        }

        $dumpPath = $container->getParameter('debug.container.dump');
        if (!\is_string($dumpPath) || '' === $dumpPath || !is_file($dumpPath)) {
            return [];
        }

        $builder = new ContainerBuilder();
        (new XmlFileLoader($builder, new FileLocator(\dirname($dumpPath))))->load($dumpPath);

        $decorators = [];
        foreach ($builder->findTaggedServiceIds('container.decorator') as $outermostId => $tags) {
            foreach ($tags as $tag) {
                if (!\is_array($tag)) {
                    continue;
                }

                $originalId = $tag['id'] ?? null;
                $terminalId = $tag['inner'] ?? null;
                if (!\is_string($originalId) || !\is_string($terminalId)) {
                    continue;
                }

                if (!str_starts_with($originalId, 'sylius.') && !str_starts_with($originalId, 'sylius_')) {
                    continue;
                }

                $originalClass = $builder->hasDefinition($terminalId) ? $builder->getDefinition($terminalId)->getClass() : null;
                $chain = $this->walkChain($builder, $outermostId);
                foreach ($chain as $position => $decoratorId) {
                    $class = $builder->getDefinition($decoratorId)->getClass();
                    $package = \is_string($class) ? ComposerPackageResolver::resolve($class, $projectDir, $lock) : null;
                    $decorators[] = [
                        'original_service_id' => $originalId,
                        'original_class' => $originalClass,
                        'decorator_service_id' => $decoratorId,
                        'decorator_class' => $class,
                        'decorator_package' => $package['name'] ?? null,
                        'decorator_package_version' => $package['version'] ?? null,
                        'chain_position' => $position + 1,
                        'chain_length' => \count($chain),
                    ];
                }
            }
        }

        usort($decorators, static fn (array $a, array $b): int => [$a['original_service_id'], $a['chain_position']] <=> [$b['original_service_id'], $b['chain_position']]);

        return $decorators;
    }

    /**
     * Decorator ids outermost-first: follows `<id>.inner` aliases until the
     * id holds a definition (the original) or nothing at all.
     *
     * @return list<string>
     */
    private function walkChain(ContainerBuilder $builder, string $outermostId): array
    {
        $chain = [];
        $currentId = $outermostId;
        while ($builder->hasDefinition($currentId) && !\in_array($currentId, $chain, true)) {
            $chain[] = $currentId;
            $innerId = $currentId . '.inner';
            if (!$builder->hasAlias($innerId)) {
                break;
            }

            $currentId = (string) $builder->getAlias($innerId);
        }

        return $chain;
    }
}
