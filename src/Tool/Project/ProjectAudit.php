<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Project;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Output\Envelope;

#[McpTool(
    name: 'sylius_project_audit',
    description: 'Run baseline Sylius-Standard convention checks against the host project: services.yaml glob excludes, core repo aliases, sync messenger in dev, framework.router.default_uri, mailer DSN observable, twig_hooks dir, project CLAUDE.md cache-clear allowance. Returns checks[] with present|absent|partial|divergent + patches_available hints. Call once per session to surface baseline gaps.',
)]
final class ProjectAudit
{
    private const REQUIRED_APP_EXCLUDES = [
        '../src/Entity/',
        '../src/Kernel.php',
        '../src/DependencyInjection/',
    ];

    private const CORE_REPO_ALIASES = [
        'Sylius\\Component\\Core\\Repository\\ProductVariantRepositoryInterface' => 'sylius.repository.product_variant',
        'Sylius\\Component\\Core\\Repository\\ProductRepositoryInterface' => 'sylius.repository.product',
        'Sylius\\Component\\Core\\Repository\\OrderRepositoryInterface' => 'sylius.repository.order',
        'Sylius\\Component\\Channel\\Repository\\ChannelRepositoryInterface' => 'sylius.repository.channel',
        'Sylius\\Component\\Customer\\Repository\\CustomerRepositoryInterface' => 'sylius.repository.customer',
    ];

    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return Envelope::guard(fn (): array => $this->audit());
    }

    /**
     * @return array<string, mixed>
     */
    private function audit(): array
    {
        $projectDir = HostProjectDir::resolve($this->host);
        $checks = [];

        $servicesYaml = $projectDir . '/config/services.yaml';
        $servicesYamlBody = is_file($servicesYaml) ? (string) @file_get_contents($servicesYaml) : '';

        $checks[] = $this->checkAppGlobExclude($servicesYamlBody);
        $checks[] = $this->checkCoreRepoAliases($projectDir);
        $checks[] = $this->checkMessengerSyncInDev($projectDir);
        $checks[] = $this->checkRouterDefaultUri();
        $checks[] = $this->checkMailerObservable();
        $checks[] = $this->checkTwigHooksDir($projectDir);
        $checks[] = $this->checkClaudeMdCacheClear($projectDir);
        $checks[] = $this->checkVariantRestockCommand($projectDir);

        $patches = [];
        foreach ($checks as $check) {
            if (isset($check['fix']) && \in_array($check['status'], ['absent', 'partial', 'divergent'], true)) {
                $patches[] = ['check' => $check['name'], 'fix' => $check['fix']];
            }
        }

        $envelope = Envelope::items($checks, null, sprintf(
            '%d check(s); %d patch hint(s). Apply patches via sylius_services_yaml_patch_exclude or manual edits per fix hints.',
            \count($checks),
            \count($patches),
        ));

        $envelope['patches_available'] = $patches;

        return $envelope;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkAppGlobExclude(string $body): array
    {
        $missing = [];
        foreach (self::REQUIRED_APP_EXCLUDES as $entry) {
            if (!str_contains($body, $entry)) {
                $missing[] = $entry;
            }
        }

        if ([] === $missing) {
            return ['name' => 'app_glob_exclude', 'status' => 'present'];
        }

        return [
            'name' => 'app_glob_exclude',
            'status' => '' === $body ? 'absent' : 'partial',
            'missing' => $missing,
            'fix' => 'Add missing entries to App\\: exclude in config/services.yaml',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCoreRepoAliases(string $projectDir): array
    {
        $globs = [
            $projectDir . '/config/services.yaml',
            $projectDir . '/config/services/_core_repo_aliases.yaml',
        ];

        foreach (glob($projectDir . '/config/services/*.yaml') ?: [] as $file) {
            $globs[] = $file;
        }

        $body = '';
        foreach (array_unique($globs) as $file) {
            if (is_file($file)) {
                $body .= (string) @file_get_contents($file);
            }
        }

        $missing = [];
        foreach (self::CORE_REPO_ALIASES as $fqcn => $alias) {
            if (!str_contains($body, $fqcn)) {
                $missing[] = $fqcn;
            }
        }

        if ([] === $missing) {
            return ['name' => 'core_repo_aliases', 'status' => 'present'];
        }

        return [
            'name' => 'core_repo_aliases',
            'status' => \count($missing) === \count(self::CORE_REPO_ALIASES) ? 'absent' : 'partial',
            'missing' => $missing,
            'fix' => 'Declare alias services for missing core repository interfaces in config/services/_core_repo_aliases.yaml',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkMessengerSyncInDev(string $projectDir): array
    {
        $candidates = [
            $projectDir . '/config/packages/dev/messenger.yaml',
            $projectDir . '/config/packages/messenger.yaml',
        ];

        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }

            $body = (string) @file_get_contents($file);
            if (preg_match('/dsn:\\s*[\'"]?sync:\\/\\//', $body)) {
                return ['name' => 'messenger_sync_in_dev', 'status' => 'present'];
            }
        }

        return [
            'name' => 'messenger_sync_in_dev',
            'status' => 'absent',
            'fix' => 'Route async transport to sync:// in when@dev so listeners run inline in tests/dev.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRouterDefaultUri(): array
    {
        $container = $this->host->getContainer();
        if ($container instanceof \Symfony\Component\DependencyInjection\Container && $container->hasParameter('router.default_uri')) {
            $value = $container->getParameter('router.default_uri');
            if (\is_string($value) && '' !== $value) {
                return ['name' => 'framework_router_default_uri', 'status' => 'present', 'current' => $value];
            }
        }

        return [
            'name' => 'framework_router_default_uri',
            'status' => 'absent',
            'fix' => 'Set framework.router.default_uri (e.g. http://localhost:8000) so CLI-generated absolute URLs work.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkMailerObservable(): array
    {
        $dsnRaw = $_SERVER['MAILER_DSN'] ?? $_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: '';
        $dsn = \is_string($dsnRaw) ? $dsnRaw : '';

        if ('' === $dsn) {
            return [
                'name' => 'mailer_observable',
                'status' => 'absent',
                'fix' => 'Set MAILER_DSN (e.g. smtp://mailpit:1025) so emails are observable in dev.',
            ];
        }

        if (str_starts_with($dsn, 'null:')) {
            return [
                'name' => 'mailer_observable',
                'status' => 'divergent',
                'current_dsn' => $dsn,
                'fix' => 'Switch MAILER_DSN off null:// to mailpit/maildev for observable email assertions.',
            ];
        }

        return ['name' => 'mailer_observable', 'status' => 'present', 'current_dsn' => $dsn];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkTwigHooksDir(string $projectDir): array
    {
        $sectional = glob($projectDir . '/config/packages/twig_hooks/*/*.yaml') ?: [];
        if ([] !== $sectional) {
            return ['name' => 'twig_hooks_dir', 'status' => 'present', 'convention' => 'sectional'];
        }

        $flat = glob($projectDir . '/config/packages/twig_hooks/*.yaml') ?: [];
        if ([] !== $flat) {
            return ['name' => 'twig_hooks_dir', 'status' => 'present', 'convention' => 'flat'];
        }

        return [
            'name' => 'twig_hooks_dir',
            'status' => 'absent',
            'fix' => 'Create config/packages/twig_hooks/<section>/<feature>.yaml convention for hook configs.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkClaudeMdCacheClear(string $projectDir): array
    {
        $claudeMd = $projectDir . '/CLAUDE.md';
        if (!is_file($claudeMd)) {
            return [
                'name' => 'project_claude_md',
                'status' => 'absent',
                'fix' => 'Add a project CLAUDE.md with sylius_cache_clear allowance + scaffold tool index.',
            ];
        }

        $body = (string) @file_get_contents($claudeMd);
        if (!str_contains($body, 'sylius_cache_clear') && !str_contains($body, 'cache:clear')) {
            return [
                'name' => 'project_claude_md',
                'status' => 'partial',
                'fix' => 'Add sylius_cache_clear allowance + scaffold tool index to CLAUDE.md.',
            ];
        }

        return ['name' => 'project_claude_md', 'status' => 'present'];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkVariantRestockCommand(string $projectDir): array
    {
        $candidates = [
            $projectDir . '/src/Command/RestockVariantCommand.php',
            $projectDir . '/src/Command/VariantRestockCommand.php',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return ['name' => 'variant_restock_command', 'status' => 'present', 'path' => $file];
            }
        }

        return [
            'name' => 'variant_restock_command',
            'status' => 'absent',
            'fix' => 'Add bin/console app:variant:restock command (MSI-aware if multi-source-inventory-plugin installed) for Playwright stock setup.',
        ];
    }
}
