<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Service;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Service\ServicesYamlPatchExclude;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class ServicesYamlPatchExcludeTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sylius-mate-patch-' . bin2hex(random_bytes(4));
        mkdir($this->sandbox . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->sandbox);
    }

    public function testAddsExcludeEntry(): void
    {
        $yaml = <<<YAML
services:
    App\\:
        resource: '../src/'
        exclude:
            - '../src/Entity/'

YAML;
        file_put_contents($this->sandbox . '/config/services.yaml', $yaml);

        $tool = new ServicesYamlPatchExclude($this->host());
        $result = ($tool)('../src/Form/');

        self::assertTrue($result['written']);
        self::assertFalse($result['noop']);
        $body = (string) file_get_contents($this->sandbox . '/config/services.yaml');
        self::assertStringContainsString("'../src/Form/'", $body);
    }

    public function testNoopWhenAlreadyPresent(): void
    {
        $yaml = <<<YAML
services:
    App\\:
        resource: '../src/'
        exclude:
            - '../src/Entity/'
            - '../src/Form/'

YAML;
        file_put_contents($this->sandbox . '/config/services.yaml', $yaml);

        $tool = new ServicesYamlPatchExclude($this->host());
        $result = ($tool)('../src/Form/');

        self::assertTrue($result['noop']);
        self::assertFalse($result['written']);
    }

    public function testErrorsWhenServicesYamlMissing(): void
    {
        $tool = new ServicesYamlPatchExclude($this->host());
        $result = ($tool)('../src/Form/');

        self::assertSame('services_yaml_missing', $result['error']['code']);
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
