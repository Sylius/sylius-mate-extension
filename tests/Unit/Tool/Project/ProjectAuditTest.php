<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Project;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Project\ProjectAudit;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class ProjectAuditTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sylius-mate-audit-' . bin2hex(random_bytes(4));
        mkdir($this->sandbox . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->sandbox);
    }

    public function testAuditFlagsMissingPieces(): void
    {
        file_put_contents($this->sandbox . '/config/services.yaml', "services:\n    App\\:\n        resource: '../src/'\n");

        $tool = new ProjectAudit($this->host());
        $result = ($tool)();

        $byName = [];
        foreach ($result['items'] as $check) {
            $byName[$check['name']] = $check;
        }

        self::assertSame('partial', $byName['app_glob_exclude']['status']);
        self::assertSame('absent', $byName['messenger_sync_in_dev']['status']);
        self::assertNotEmpty($result['patches_available']);
    }

    private function host(): FakeHostContainerProvider
    {
        return new FakeHostContainerProvider(new Container(new ParameterBag([
            'kernel.project_dir' => $this->sandbox,
        ])));
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $child = $path . '/' . $item;
            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
