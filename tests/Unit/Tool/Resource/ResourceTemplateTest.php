<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Resource;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tool\Resource\ResourceTemplate;

final class ResourceTemplateTest extends TestCase
{
    private ResourceTemplate $tool;

    protected function setUp(): void
    {
        $this->tool = new ResourceTemplate(\dirname(__DIR__, 4) . '/src/Scaffold');
    }

    public function testRejectsInvalidAlias(): void
    {
        $result = ($this->tool)('not-an-alias', 'Foo', 'App');

        self::assertSame('invalid_alias', $result['error']['code']);
    }

    public function testRejectsInvalidModelShortName(): void
    {
        $result = ($this->tool)('app.foo', 'foo', 'App');

        self::assertSame('invalid_model_short_name', $result['error']['code']);
    }

    public function testEmitsScaffoldWithoutFactoryByDefault(): void
    {
        $result = ($this->tool)('app.back_in_stock_notification', 'BackInStockNotification', 'App');

        self::assertArrayNotHasKey('error', $result);

        $kinds = array_column($result['items'], 'kind');
        self::assertSame([
            'entity',
            'interface',
            'repository',
            'repository_interface',
            'form',
            'grid_config',
            'form_service',
            'resource_config',
            'services_yaml_patch',
        ], $kinds);

        $patch = $this->bodyOfKind($result['items'], 'services_yaml_patch');
        self::assertStringContainsString("- '../src/Form/'", $patch);
        self::assertStringContainsString("imports:\n    - { resource: 'services/' }", $patch);

        $yaml = $this->bodyOfKind($result['items'], 'resource_config');
        self::assertStringContainsString('app.back_in_stock_notification:', $yaml);
        self::assertStringContainsString('model: App\\Entity\\BackInStockNotification', $yaml);
        self::assertStringNotContainsString('factory:', $yaml);
    }

    public function testWithCustomFactoryEmitsFactoryFilesAndYamlEntry(): void
    {
        $result = ($this->tool)('app.foo', 'Foo', 'App', with_custom_factory: true);

        $kinds = array_column($result['items'], 'kind');
        self::assertContains('factory', $kinds);
        self::assertContains('factory_interface', $kinds);

        $yaml = $this->bodyOfKind($result['items'], 'resource_config');
        self::assertStringContainsString('factory: App\\Factory\\FooFactory', $yaml);

        self::assertNotEmpty($result['warnings']);
        self::assertStringContainsString('__construct(string $className)', $result['warnings'][0]);
    }

    public function testAppendsAdminHookFilesWhenRequested(): void
    {
        $result = ($this->tool)('app.foo', 'Foo', 'App', with_admin_hook: true);

        $kinds = array_column($result['items'], 'kind');
        self::assertContains('admin_grid_hook', $kinds);
        self::assertContains('admin_grid_menu_template', $kinds);
    }

    public function testNormalizesMailerCodeWithAppPrefix(): void
    {
        $result = ($this->tool)('app.foo', 'Foo', 'App', mailer_code: 'foo_notification');

        $mailer = $this->bodyOfKind($result['items'], 'mailer_config');
        self::assertStringContainsString('app_foo_notification', $mailer);
        self::assertStringContainsString('app.email.app_foo_notification.subject', $mailer);
        self::assertSame('app_foo_notification', $result['summary']['mailer_code']);
        self::assertNotEmpty($result['warnings']);
    }

    public function testKeepsMailerCodeWhenAlreadyPrefixed(): void
    {
        $result = ($this->tool)('app.foo', 'Foo', 'App', mailer_code: 'app_thing');

        self::assertSame('app_thing', $result['summary']['mailer_code']);
        self::assertArrayNotHasKey('warnings', $result);
    }

    public function testRejectsInvalidMailerCode(): void
    {
        $result = ($this->tool)('app.foo', 'Foo', 'App', mailer_code: 'BAD-CODE');

        self::assertSame('invalid_mailer_code', $result['error']['code']);
    }

    public function testWithComponentUsesSyliusConventionNamespace(): void
    {
        $result = ($this->tool)('app.foo', 'Foo', 'App', with_component: true);

        $component = $this->bodyOfKind($result['items'], 'component');
        self::assertStringContainsString('namespace App\\TwigComponent\\Foo;', $component);
    }

    public function testEmitsCoreRepoAliasesWhenRequested(): void
    {
        $result = ($this->tool)('app.foo', 'Foo', 'App', core_repos: ['product_variant', 'channel']);

        $aliases = $this->bodyOfKind($result['items'], 'core_repo_aliases');
        self::assertStringContainsString('Sylius\\Component\\Core\\Repository\\ProductVariantRepositoryInterface:', $aliases);
        self::assertStringContainsString('alias: sylius.repository.product_variant', $aliases);
        self::assertStringContainsString('Sylius\\Component\\Channel\\Repository\\ChannelRepositoryInterface:', $aliases);
    }

    public function testRejectsUnknownCoreRepo(): void
    {
        $result = ($this->tool)('app.foo', 'Foo', 'App', core_repos: ['gibberish']);

        self::assertSame('unknown_core_repo', $result['error']['code']);
    }

    public function testMigrationCleanupInstructionsInNoteAndField(): void
    {
        $result = ($this->tool)('app.foo', 'Foo', 'App');

        self::assertNotEmpty($result['migration_cleanup']);
        self::assertStringContainsString('strip the "Auto-generated Migration', $result['migration_cleanup'][1]);
        self::assertStringContainsString('doctrine:migrations:diff', $result['note']);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function bodyOfKind(array $items, string $kind): string
    {
        foreach ($items as $item) {
            if ($item['kind'] === $kind) {
                return $item['body'];
            }
        }

        self::fail(sprintf('No item of kind "%s".', $kind));
    }
}
