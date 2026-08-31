<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Resource;

use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;

final class ResourceTemplate
{
    /** @var array<string, string> */
    private array $bodies = [];

    private const CORE_REPO_INTERFACES = [
        'product_variant' => 'Sylius\\Component\\Core\\Repository\\ProductVariantRepositoryInterface',
        'product' => 'Sylius\\Component\\Core\\Repository\\ProductRepositoryInterface',
        'channel' => 'Sylius\\Component\\Channel\\Repository\\ChannelRepositoryInterface',
        'customer' => 'Sylius\\Component\\Customer\\Repository\\CustomerRepositoryInterface',
        'order' => 'Sylius\\Component\\Core\\Repository\\OrderRepositoryInterface',
        'payment_method' => 'Sylius\\Component\\Core\\Repository\\PaymentMethodRepositoryInterface',
        'shipping_method' => 'Sylius\\Component\\Core\\Repository\\ShippingMethodRepositoryInterface',
        'taxon' => 'Sylius\\Component\\Core\\Repository\\TaxonRepositoryInterface',
    ];

    public function __construct(
        private readonly string $scaffoldDir,
    ) {
    }

    /**
     * @param list<string> $core_repos
     *
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_domain_resource_template',
        description: 'Emit scaffold for a new Sylius Resource: entity, interface, repository + interface, form, sylius_resource yaml, grid yaml, form-type service yaml. By default no Factory class is emitted (Sylius wires the default Factory automatically). Opt-in flags: with_custom_factory (adds factory + interface + classes.factory line — signature MUST be __construct(string $className)); with_admin_hook; with_component (TwigComponent skeleton under App\\TwigComponent\\<Section>); mailer_code (sylius_mailer + email template); core_repos (list of Sylius core repo names whose interface aliases to expose, e.g. ["product_variant", "channel"]).',
    )]
    public function __invoke(
        string $alias,
        string $model_short_name,
        ?string $namespace = null,
        bool $with_admin_hook = false,
        ?string $mailer_code = null,
        bool $with_component = false,
        bool $with_custom_factory = false,
        array $core_repos = [],
        bool $with_listener = false,
    ): array {
        $namespace = null !== $namespace && '' !== trim($namespace) ? $namespace : $this->detectAppNamespace();

        if ('' === trim($alias) || !preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $alias)) {
            return Envelope::error(
                'invalid_alias',
                'Argument "alias" must look like "<app_prefix>.<resource_name>", e.g. "app.back_in_stock_notification".',
                'Call sylius_domain_list_resources to see existing alias patterns.',
            );
        }

        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $model_short_name)) {
            return Envelope::error(
                'invalid_model_short_name',
                'Argument "model_short_name" must be a PascalCase class short name, e.g. "BackInStockNotification".',
            );
        }

        if (!preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/', $namespace)) {
            return Envelope::error('invalid_namespace', 'Argument "namespace" must be a PSR-4 namespace prefix, e.g. "App".');
        }

        $tableName = $this->aliasToTable($alias);
        $blockPrefix = str_replace('.', '_', $alias);
        $gridName = str_replace('.', '_', $alias);

        $normalizedMailerCode = $mailer_code;
        if (null !== $mailer_code) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $mailer_code)) {
                return Envelope::error('invalid_mailer_code', 'Argument "mailer_code" must be snake_case, e.g. "back_in_stock".');
            }

            if (!str_starts_with($mailer_code, 'app_')) {
                $normalizedMailerCode = 'app_' . $mailer_code;
            }
        }

        $unknownRepos = array_diff($core_repos, array_keys(self::CORE_REPO_INTERFACES));
        if ([] !== $unknownRepos) {
            return Envelope::error(
                'unknown_core_repo',
                sprintf('Unknown core_repos: %s.', implode(', ', $unknownRepos)),
                sprintf('Supported: %s.', implode(', ', array_keys(self::CORE_REPO_INTERFACES))),
            );
        }

        $componentSection = $model_short_name;

        $vars = [
            '{{ alias }}' => $alias,
            '{{ model }}' => $model_short_name,
            '{{ namespace }}' => $namespace,
            '{{ table_name }}' => $tableName,
            '{{ block_prefix }}' => $blockPrefix,
            '{{ grid_name }}' => $gridName,
            '{{ mailer_code }}' => $normalizedMailerCode ?? '',
            '{{ mailer_subject_key }}' => null === $normalizedMailerCode ? '' : sprintf('app.email.%s.subject', $normalizedMailerCode),
            '{{ component_name }}' => $blockPrefix,
            '{{ component_section }}' => $componentSection,
        ];

        $files = [
            ['kind' => 'entity', 'path' => sprintf('src/Entity/%s.php', $model_short_name), 'template' => 'entity.php.tpl'],
            ['kind' => 'interface', 'path' => sprintf('src/Entity/%sInterface.php', $model_short_name), 'template' => 'interface.php.tpl'],
            ['kind' => 'repository', 'path' => sprintf('src/Repository/%sRepository.php', $model_short_name), 'template' => 'repository.php.tpl'],
            ['kind' => 'repository_interface', 'path' => sprintf('src/Repository/%sRepositoryInterface.php', $model_short_name), 'template' => 'repository_interface.php.tpl'],
            ['kind' => 'form', 'path' => sprintf('src/Form/Type/%sType.php', $model_short_name), 'template' => 'form.php.tpl'],
            ['kind' => 'grid_config', 'path' => sprintf('config/packages/grids/%s.yaml', $gridName), 'template' => 'grid.yaml.tpl'],
            ['kind' => 'form_service', 'path' => sprintf('config/services/%s_form.yaml', $gridName), 'template' => 'form_service.yaml.tpl'],
        ];

        if ($with_custom_factory) {
            $files[] = ['kind' => 'factory', 'path' => sprintf('src/Factory/%sFactory.php', $model_short_name), 'template' => 'factory.php.tpl'];
            $files[] = ['kind' => 'factory_interface', 'path' => sprintf('src/Factory/%sFactoryInterface.php', $model_short_name), 'template' => 'factory_interface.php.tpl'];
        }

        if ($with_component) {
            $files[] = ['kind' => 'component', 'path' => sprintf('src/TwigComponent/%s/%sComponent.php', $componentSection, $model_short_name), 'template' => 'component.php.tpl'];
        }

        if ($with_admin_hook) {
            $files[] = ['kind' => 'admin_grid_hook', 'path' => sprintf('config/packages/%s_admin_hook.yaml', $gridName), 'template' => 'admin_grid_hook.yaml.tpl'];
            $files[] = ['kind' => 'admin_grid_menu_template', 'path' => sprintf('templates/admin/%s/_sidebar_menu_entry.html.twig', $gridName), 'template' => 'admin_grid_menu.html.twig.tpl'];
        }

        if ($with_listener) {
            $files[] = ['kind' => 'listener', 'path' => sprintf('src/EventListener/%sListener.php', $model_short_name), 'template' => 'listener.php.tpl'];
        }

        if (null !== $normalizedMailerCode) {
            $files[] = ['kind' => 'mailer_config', 'path' => sprintf('config/packages/sylius_mailer_%s.yaml', $normalizedMailerCode), 'template' => 'mailer.yaml.tpl'];
            $files[] = ['kind' => 'mailer_template', 'path' => sprintf('templates/email/%s.html.twig', $normalizedMailerCode), 'template' => 'mailer_template.html.twig.tpl'];
        }

        $items = [];
        foreach ($files as $file) {
            $body = $this->render($file['template'], $vars);
            if (null === $body) {
                return Envelope::error('template_missing', sprintf('Scaffold template "%s" not found.', $file['template']));
            }

            $items[] = [
                'kind' => $file['kind'],
                'suggested_path' => $file['path'],
                'body' => $body,
            ];
        }

        $items[] = [
            'kind' => 'resource_config',
            'suggested_path' => sprintf('config/packages/%s.yaml', $gridName),
            'body' => $this->buildResourceYaml($alias, $namespace, $model_short_name, $with_custom_factory),
        ];

        if ([] !== $core_repos) {
            $items[] = [
                'kind' => 'core_repo_aliases',
                'suggested_path' => 'config/services/_core_repo_aliases.yaml',
                'body' => $this->buildCoreRepoAliases($core_repos),
            ];
        }

        $items[] = [
            'kind' => 'services_yaml_patch',
            'suggested_path' => 'config/services.yaml',
            'body' => $this->buildServicesYamlPatch($gridName, $namespace),
        ];

        $warnings = [];
        if ($with_custom_factory) {
            $warnings[] = 'Custom factory class signature MUST be __construct(string $className) (Sylius default). To wrap or decorate, declare a separate service and inject by id; do NOT point classes.factory at the wrapper.';
        }

        if (null !== $mailer_code && $mailer_code !== $normalizedMailerCode) {
            $warnings[] = sprintf('Mailer code "%s" was normalized to "%s" (Sylius convention: app_ prefix).', $mailer_code, $normalizedMailerCode);
        }

        $migrationCleanup = [
            'Run "bin/console doctrine:migrations:diff" to generate the migration.',
            'In the generated migration: strip the "Auto-generated Migration: Please modify to your needs!" docblock and the "this up()/down() migration is auto-generated..." inline comments. Fill getDescription() with a one-line summary.',
        ];

        $envelope = Envelope::items($items, null, sprintf(
            'Write every file in items[]. Then: %s Call sylius_resource_inspect with alias="%s" for diagnostics; call sylius_domain_list_grids to confirm grid "%s" is registered.',
            implode(' ', $migrationCleanup),
            $alias,
            $gridName,
        ));

        $envelope['summary'] = [
            'alias' => $alias,
            'model_short_name' => $model_short_name,
            'namespace' => $namespace,
            'kinds' => array_column($items, 'kind'),
            'paths' => array_column($items, 'suggested_path'),
            'mailer_code' => $normalizedMailerCode,
            'core_repos' => $core_repos,
            'custom_factory' => $with_custom_factory,
        ];

        if ([] !== $warnings) {
            $envelope['warnings'] = $warnings;
        }

        $envelope['migration_cleanup'] = $migrationCleanup;

        return $envelope;
    }

    private function buildResourceYaml(string $alias, string $namespace, string $model, bool $customFactory): string
    {
        $lines = [
            'sylius_resource:',
            '    resources:',
            sprintf('        %s:', $alias),
            '            classes:',
            sprintf('                model: %s\\Entity\\%s', $namespace, $model),
            sprintf('                interface: %s\\Entity\\%sInterface', $namespace, $model),
            sprintf('                repository: %s\\Repository\\%sRepository', $namespace, $model),
        ];

        if ($customFactory) {
            $lines[] = sprintf('                factory: %s\\Factory\\%sFactory', $namespace, $model);
        }

        $lines[] = sprintf('                form: %s\\Form\\Type\\%sType', $namespace, $model);

        return implode("\n", $lines) . "\n";
    }

    private function buildServicesYamlPatch(string $gridName, string $namespace): string
    {
        return implode("\n", [
            '# Idempotent patch — merge into config/services.yaml',
            '# Goal: prevent explicit defs in config/services/' . $gridName . '_form.yaml from being silently overridden by the ' . $namespace . '\\ glob.',
            '',
            'imports:',
            "    - { resource: 'services/' }",
            '',
            'services:',
            '    ' . $namespace . '\\:',
            "        resource: '../src/'",
            '        exclude:',
            "            - '../src/DependencyInjection/'",
            "            - '../src/Entity/'",
            "            - '../src/Kernel.php'",
            "            - '../src/Form/'",
            "            - '../src/EventListener/'",
            "            - '../src/Message/'",
            "            - '../src/Factory/'",
            "            - '../src/Repository/'",
            '',
        ]) . "\n";
    }

    /**
     * @param list<string> $repos
     */
    private function buildCoreRepoAliases(array $repos): string
    {
        $lines = ['services:'];
        foreach ($repos as $repo) {
            $fqcn = self::CORE_REPO_INTERFACES[$repo];
            $lines[] = sprintf('    %s:', $fqcn);
            $lines[] = sprintf('        alias: sylius.repository.%s', $repo);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $vars
     */
    private function render(string $template, array $vars): ?string
    {
        if (!isset($this->bodies[$template])) {
            $body = @file_get_contents($this->scaffoldDir . '/' . $template);
            if (false === $body) {
                return null;
            }

            $this->bodies[$template] = $body;
        }

        return strtr($this->bodies[$template], $vars);
    }

    private function aliasToTable(string $alias): string
    {
        return str_replace('.', '_', $alias);
    }

    private function detectAppNamespace(): string
    {
        $cwd = getcwd();
        if (false === $cwd) {
            return 'App';
        }

        $composerJson = $cwd . '/composer.json';
        if (!is_file($composerJson)) {
            return 'App';
        }

        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode((string) file_get_contents($composerJson), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'App';
        }

        $autoload = $composer['autoload'] ?? [];
        $psr4 = \is_array($autoload) ? ($autoload['psr-4'] ?? []) : [];
        if (!\is_array($psr4)) {
            return 'App';
        }

        foreach ($psr4 as $ns => $paths) {
            if (!\is_string($ns) || '' === $ns) {
                continue;
            }

            foreach ((array) $paths as $path) {
                if (\is_string($path) && is_file(rtrim($cwd . '/' . $path, '/') . '/Kernel.php')) {
                    return rtrim($ns, '\\');
                }
            }
        }

        return 'App';
    }
}
