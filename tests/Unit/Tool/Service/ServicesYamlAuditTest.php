<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Service;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Service\ServicesYamlAudit;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class ServicesYamlAuditTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sylius-mate-audit-' . bin2hex(random_bytes(4));
        mkdir($this->sandbox . '/config/services', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->sandbox);
    }

    public function testDetectsExplicitDefOverlappingGlob(): void
    {
        file_put_contents($this->sandbox . '/config/services.yaml', <<<'YAML'
            imports:
                - { resource: 'services/' }

            services:
                _defaults:
                    autowire: true

                App\:
                    resource: '../src/'
                    exclude:
                        - '../src/Entity/'
            YAML);

        file_put_contents($this->sandbox . '/config/services/back_in_stock.yaml', <<<'YAML'
            services:
                App\Form\Type\Notification\BackInStockNotificationType:
                    class: App\Form\Type\Notification\BackInStockNotificationType
                    arguments:
                        - 'App\Entity\Notification\BackInStockNotification'
                    tags: [form.type]
            YAML);

        $tool = new ServicesYamlAudit($this->host());

        $result = $tool();

        self::assertCount(1, $result['conflicts']);
        self::assertSame('App\\Form\\Type\\Notification\\BackInStockNotificationType', $result['conflicts'][0]['service_id']);
        self::assertStringContainsString('Form', $result['conflicts'][0]['fix']);
    }

    public function testReportsNoConflictWhenExcludeCoversPath(): void
    {
        file_put_contents($this->sandbox . '/config/services.yaml', <<<'YAML'
            imports:
                - { resource: 'services/' }

            services:
                App\:
                    resource: '../src/'
                    exclude:
                        - '../src/Form/'
            YAML);

        file_put_contents($this->sandbox . '/config/services/back_in_stock.yaml', <<<'YAML'
            services:
                App\Form\Type\Notification\BackInStockNotificationType:
                    class: App\Form\Type\Notification\BackInStockNotificationType
                    tags: [form.type]
            YAML);

        $tool = new ServicesYamlAudit($this->host());

        $result = $tool();

        self::assertSame([], $result['conflicts']);
    }

    private function host(): FakeHostContainerProvider
    {
        $container = new Container(new ParameterBag(['kernel.project_dir' => $this->sandbox]));

        return new FakeHostContainerProvider($container);
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $child = $path . '/' . $item;
            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
