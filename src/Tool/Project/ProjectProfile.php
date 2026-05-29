<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Project;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Output\Envelope;
use Webmozart\Assert\Assert;

#[McpTool(
    name: 'sylius_project_profile',
    description: 'Auto-detect the host project shape: app namespace (from composer.json psr-4), src/config/translations dirs, enabled locales, default locale, default channel, MAILER_DSN observability, messenger transport mode, services.yaml App\\ glob exclude entries, hook config dir convention. Every other scaffold tool should consume this profile to stop hardcoding App\\, messages.en_US.yaml, single-locale assumptions.',
)]
final class ProjectProfile
{
    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return Envelope::guard(fn (): array => $this->profile());
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(): array
    {
        $projectDir = HostProjectDir::resolve($this->host);
        $appNamespace = $this->detectAppNamespace($projectDir);

        $container = $this->host->getContainer();
        $isContainer = $container instanceof \Symfony\Component\DependencyInjection\Container;

        $defaultLocale = $isContainer && $container->hasParameter('kernel.default_locale')
            ? $this->stringParameter($container, 'kernel.default_locale', 'en_US')
            : 'en_US';

        $enabledLocales = $this->detectEnabledLocales($container, $projectDir, $defaultLocale);

        $defaultChannelCode = $isContainer && $container->hasParameter('sylius.channel.default_code')
            ? $this->stringParameter($container, 'sylius.channel.default_code', 'default')
            : 'default';

        $mailerDsnRaw = $_SERVER['MAILER_DSN'] ?? $_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: '';
        $mailerDsn = \is_string($mailerDsnRaw) ? $mailerDsnRaw : '';
        if ('' === $mailerDsn && $isContainer && $container->hasParameter('env(MAILER_DSN)')) {
            $mailerDsn = $this->stringParameter($container, 'env(MAILER_DSN)', '');
        }
        $mailerObservable = $this->isMailerObservable($mailerDsn);

        $messengerSyncInDev = $this->detectMessengerSyncInDev($projectDir);
        $framework_router_default_uri = $this->detectRouterDefaultUri($container);

        $excludes = $this->detectAppGlobExcludes($projectDir, $appNamespace);
        $controllersShopExcluded = $this->controllerShopExcluded($excludes);
        $hookConvention = $this->detectHookConvention($projectDir);

        $item = [
            'app_namespace' => $appNamespace,
            'app_namespace_with_separator' => $appNamespace . '\\',
            'src_dir' => 'src',
            'config_dir' => 'config',
            'translations_dir' => 'translations',
            'enabled_locales' => $enabledLocales,
            'default_locale' => $defaultLocale,
            'default_channel_code' => $defaultChannelCode,
            'mailer_dsn' => '' === $mailerDsn ? null : $mailerDsn,
            'mailer_dsn_observable' => $mailerObservable,
            'messenger_async_routes_to_sync_in_dev' => $messengerSyncInDev,
            'framework_router_default_uri' => $framework_router_default_uri,
            'services_yaml_app_glob_exclude' => $excludes,
            'controllers_shop_glob_excluded' => $controllersShopExcluded,
            'hook_config_dir_convention' => $hookConvention,
            'project_dir' => $projectDir,
        ];

        return Envelope::items([$item], null, sprintf(
            'Project profile for namespace "%s". Feed app_namespace + enabled_locales into sylius_domain_resource_template / sylius_translation_create to keep scaffolds universal.',
            $appNamespace,
        ));
    }

    private function stringParameter(\Symfony\Component\DependencyInjection\Container $container, string $name, string $default): string
    {
        $value = $container->getParameter($name);

        return \is_string($value) ? $value : $default;
    }

    private function detectAppNamespace(string $projectDir): string
    {
        $composerJson = $projectDir . '/composer.json';
        if (!is_file($composerJson)) {
            return 'App';
        }

        $raw = @file_get_contents($composerJson);
        if (false === $raw) {
            return 'App';
        }

        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'App';
        }

        $autoload = $composer['autoload'] ?? [];
        $psr4 = \is_array($autoload) ? ($autoload['psr-4'] ?? []) : [];
        if (!\is_array($psr4)) {
            return 'App';
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

                $kernelFile = rtrim($projectDir . '/' . $path, '/') . '/Kernel.php';
                if (is_file($kernelFile)) {
                    return rtrim($namespace, '\\');
                }
            }
        }

        foreach (array_keys($psr4) as $namespace) {
            if (\is_string($namespace) && '' !== $namespace) {
                return rtrim($namespace, '\\');
            }
        }

        return 'App';
    }

    /**
     * @return list<string>
     */
    private function detectEnabledLocales(mixed $container, string $projectDir, string $defaultLocale): array
    {
        if ($container instanceof \Symfony\Component\DependencyInjection\Container) {
            foreach (['sylius_locale.locales', 'sylius.locales', 'enabled_locales'] as $parameter) {
                if (!$container->hasParameter($parameter)) {
                    continue;
                }

                $value = $container->getParameter($parameter);
                if (\is_array($value) && [] !== $value) {
                    return array_values(array_filter(array_map(static fn (mixed $v): string => (string) (\is_scalar($v) ? $v : ''), $value)));
                }
            }
        }

        $locales = [];
        $glob = glob($projectDir . '/translations/messages.*.yaml') ?: [];
        foreach ($glob as $path) {
            if (preg_match('/messages\\.([a-z]{2}(?:_[A-Z]{2})?)\\.yaml$/', $path, $m)) {
                $locales[] = $m[1];
            }
        }

        if ([] === $locales) {
            $locales = [$defaultLocale];
        }

        return array_values(array_unique($locales));
    }

    private function isMailerObservable(string $dsn): bool
    {
        if ('' === $dsn) {
            return false;
        }

        if (str_starts_with($dsn, 'null:')) {
            return false;
        }

        return (bool) preg_match('/^(smtp|sendmail|smtps)/', $dsn);
    }

    private function detectMessengerSyncInDev(string $projectDir): bool
    {
        $candidates = [
            $projectDir . '/config/packages/dev/messenger.yaml',
            $projectDir . '/config/packages/messenger.yaml',
        ];

        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }

            $contents = (string) @file_get_contents($file);
            if (preg_match('/transport:\\s*sync/i', $contents) || preg_match('/dsn:\\s*[\'"]?sync:\\/\\//', $contents)) {
                return true;
            }
        }

        return false;
    }

    private function detectRouterDefaultUri(mixed $container): ?string
    {
        if (!$container instanceof \Symfony\Component\DependencyInjection\Container) {
            return null;
        }

        foreach (['router.request_context.host', 'router.request_context.scheme', 'router.request_context.base_url'] as $parameter) {
            if (!$container->hasParameter($parameter)) {
                continue;
            }
        }

        if ($container->hasParameter('router.default_uri')) {
            $value = $container->getParameter('router.default_uri');
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function detectAppGlobExcludes(string $projectDir, string $appNamespace): array
    {
        $servicesYaml = $projectDir . '/config/services.yaml';
        if (!is_file($servicesYaml)) {
            return [];
        }

        $contents = (string) @file_get_contents($servicesYaml);
        $lines = preg_split('/\\r?\\n/', $contents) ?: [];

        $excludes = [];
        $inApp = false;
        $inExclude = false;
        foreach ($lines as $line) {
            if (preg_match('/^\\s{4}' . preg_quote($appNamespace, '/') . '\\\\:/', $line)) {
                $inApp = true;
                $inExclude = false;
                continue;
            }

            if ($inApp && preg_match('/^\\s{0,4}\\S/', $line) && !preg_match('/^\\s{8,}/', $line)) {
                $inApp = false;
            }

            if (!$inApp) {
                continue;
            }

            if (preg_match('/^\\s{8}exclude:/', $line)) {
                $inExclude = true;
                continue;
            }

            if ($inExclude) {
                if (preg_match("/^\\s{12,}-\\s*['\"]?([^'\"\\s]+)['\"]?/", $line, $m)) {
                    $excludes[] = $m[1];

                    continue;
                }

                if (preg_match('/^\\s{0,8}\\S/', $line)) {
                    $inExclude = false;
                }
            }
        }

        return $excludes;
    }

    /**
     * @param list<string> $excludes
     */
    private function controllerShopExcluded(array $excludes): bool
    {
        foreach ($excludes as $entry) {
            if (str_contains($entry, 'Controller/Shop') || str_contains($entry, 'Controller\\Shop')) {
                return true;
            }
        }

        return false;
    }

    private function detectHookConvention(string $projectDir): string
    {
        $sectional = glob($projectDir . '/config/packages/twig_hooks/*/*.yaml') ?: [];
        if ([] !== $sectional) {
            return 'config/packages/twig_hooks/<section>/<feature>.yaml';
        }

        $flat = glob($projectDir . '/config/packages/twig_hooks/*.yaml') ?: [];
        if ([] !== $flat) {
            return 'config/packages/twig_hooks/<feature>.yaml';
        }

        return 'config/packages/<feature>_hooks.yaml';
    }
}
